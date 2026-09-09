import Foundation

final class APIClient: @unchecked Sendable {
    private let lock = NSLock()
    private var _baseURL: URL?
    private var _token: String?
    private var _organizationId: String?

    /// Called on the main actor when the server rejects the token.
    var onUnauthorized: (@MainActor () -> Void)?

    var baseURL: URL? {
        get { lock.withLock { _baseURL } }
        set { lock.withLock { _baseURL = newValue } }
    }

    var token: String? {
        get { lock.withLock { _token } }
        set { lock.withLock { _token = newValue } }
    }

    var organizationId: String? {
        get { lock.withLock { _organizationId } }
        set { lock.withLock { _organizationId = newValue } }
    }

    private let session: URLSession
    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()
    private let encoder: JSONEncoder = {
        let e = JSONEncoder()
        e.keyEncodingStrategy = .convertToSnakeCase
        return e
    }()

    init() {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 15
        config.waitsForConnectivity = false
        self.session = URLSession(configuration: config)
        if let url = Self.normalize(Preferences.serverURL) {
            _baseURL = url
        }
        _organizationId = Preferences.organizationId
    }

    /// Accepts "https://host", "host", "https://host/api" and returns ".../api".
    static func normalize(_ raw: String) -> URL? {
        var s = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !s.isEmpty else { return nil }
        if !s.hasPrefix("http://") && !s.hasPrefix("https://") {
            s = "https://" + s
        }
        while s.hasSuffix("/") { s.removeLast() }
        if !s.hasSuffix("/api") { s += "/api" }
        return URL(string: s)
    }

    func get<T: Decodable>(_ path: String, query: [String: String] = [:]) async throws -> T {
        let request = try buildRequest(path: path, method: "GET", query: query)
        return try await perform(request)
    }

    func post<T: Decodable>(_ path: String, body: (any Encodable)? = nil) async throws -> T {
        var request = try buildRequest(path: path, method: "POST")
        if let body { request.httpBody = try encoder.encode(AnyEncodable(body)) }
        return try await perform(request)
    }

    func put<T: Decodable>(_ path: String, body: any Encodable) async throws -> T {
        var request = try buildRequest(path: path, method: "PUT")
        request.httpBody = try encoder.encode(AnyEncodable(body))
        return try await perform(request)
    }

    func postNoResponse(_ path: String, body: (any Encodable)? = nil) async throws {
        var request = try buildRequest(path: path, method: "POST")
        if let body { request.httpBody = try encoder.encode(AnyEncodable(body)) }
        let (data, response) = try await session.data(for: request)
        try check(response, data: data)
    }

    private func buildRequest(path: String, method: String, query: [String: String] = [:]) throws -> URLRequest {
        guard let baseURL else { throw APIError.noServer }
        guard var components = URLComponents(string: baseURL.absoluteString + path) else { throw APIError.invalidURL }
        if !query.isEmpty {
            components.queryItems = (components.queryItems ?? []) + query.map { URLQueryItem(name: $0.key, value: $0.value) }
        }
        guard let url = components.url else { throw APIError.invalidURL }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let organizationId { request.setValue(organizationId, forHTTPHeaderField: "X-Organization-Id") }
        return request
    }

    private func perform<T: Decodable>(_ request: URLRequest) async throws -> T {
        let (data, response) = try await session.data(for: request)
        try check(response, data: data)
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }

    private func check(_ response: URLResponse, data: Data) throws {
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        if http.statusCode == 401 {
            if let handler = onUnauthorized { Task { @MainActor in handler() } }
            throw APIError.unauthorized
        }
        if http.statusCode == 202 {
            throw APIError.httpError(statusCode: 202, message: nil)
        }
        guard (200...299).contains(http.statusCode) else {
            let message = (try? JSONDecoder().decode(ErrorBody.self, from: data))?.message
            throw APIError.httpError(statusCode: http.statusCode, message: message)
        }
    }
}

private struct ErrorBody: Decodable {
    let message: String?
}

enum APIError: LocalizedError {
    case noServer
    case invalidURL
    case invalidResponse
    case unauthorized
    case httpError(statusCode: Int, message: String?)
    case decoding(String)

    var errorDescription: String? {
        switch self {
        case .noServer: return "No server configured."
        case .invalidURL: return "Invalid server URL."
        case .invalidResponse: return "Invalid response from server."
        case .unauthorized: return "Not signed in."
        case .httpError(let code, let message): return message ?? "Server error (\(code))."
        case .decoding(let detail): return "Unexpected response: \(detail)"
        }
    }
}

private struct AnyEncodable: Encodable {
    private let encodeClosure: (Encoder) throws -> Void
    init(_ value: any Encodable) { encodeClosure = { try value.encode(to: $0) } }
    func encode(to encoder: Encoder) throws { try encodeClosure(encoder) }
}
