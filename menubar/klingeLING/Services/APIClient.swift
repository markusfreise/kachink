import Foundation

@Observable
final class APIClient: @unchecked Sendable {
    var baseURL: URL
    var token: String?

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

    init(baseURL: URL = URL(string: "http://localhost:8081/api")!) {
        self.baseURL = baseURL
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 15
        self.session = URLSession(configuration: config)
    }

    func get<T: Codable & Sendable>(_ path: String) async throws -> T {
        let request = try buildRequest(path: path, method: "GET")
        return try await perform(request)
    }

    func post<T: Codable & Sendable>(_ path: String, body: (any Encodable)? = nil) async throws -> T {
        var request = try buildRequest(path: path, method: "POST")
        if let body {
            request.httpBody = try encoder.encode(AnyEncodable(body))
        }
        return try await perform(request)
    }

    func postNoResponse(_ path: String, body: (any Encodable)? = nil) async throws {
        var request = try buildRequest(path: path, method: "POST")
        if let body {
            request.httpBody = try encoder.encode(AnyEncodable(body))
        }
        let (_, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        if http.statusCode == 401 { throw APIError.unauthorized }
        guard (200...299).contains(http.statusCode) else {
            throw APIError.httpError(statusCode: http.statusCode)
        }
    }

    private func buildRequest(path: String, method: String) throws -> URLRequest {
        // Support paths with query params already
        let url: URL
        if path.contains("?") {
            guard let parsed = URL(string: "\(baseURL.absoluteString)\(path)") else {
                throw APIError.invalidURL
            }
            url = parsed
        } else {
            url = baseURL.appendingPathComponent(path)
        }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        return request
    }

    private func perform<T: Codable & Sendable>(_ request: URLRequest) async throws -> T {
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        if http.statusCode == 401 { throw APIError.unauthorized }
        guard (200...299).contains(http.statusCode) else {
            throw APIError.httpError(statusCode: http.statusCode)
        }
        return try decoder.decode(T.self, from: data)
    }
}

enum APIError: LocalizedError {
    case invalidURL
    case invalidResponse
    case unauthorized
    case httpError(statusCode: Int)

    var errorDescription: String? {
        switch self {
        case .invalidURL: return "Invalid URL"
        case .invalidResponse: return "Invalid response from server"
        case .unauthorized: return "Authentication failed"
        case .httpError(let code): return "Server error (\(code))"
        }
    }
}

// Type-erased Encodable wrapper
private struct AnyEncodable: Encodable {
    private let encodeClosure: (Encoder) throws -> Void

    init(_ value: any Encodable) {
        self.encodeClosure = { encoder in
            try value.encode(to: encoder)
        }
    }

    func encode(to encoder: Encoder) throws {
        try encodeClosure(encoder)
    }
}
