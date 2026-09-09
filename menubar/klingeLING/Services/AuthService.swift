import Foundation

struct LoginRequest: Encodable {
    let email: String
    let password: String
}

struct LoginResponse: Decodable {
    let data: UserDTO
    let token: String
}

struct OrganizationDTO: Codable, Identifiable, Hashable {
    let id: String
    let name: String
    let slug: String?
    let role: String?
}

struct DeviceStartRequest: Encodable {
    let deviceName: String
}

struct DeviceStartResponse: Decodable {
    let code: String
    let expiresIn: Int
    let pollInterval: Int
}

struct DevicePollResponse: Decodable {
    let status: String
    let token: String?
    let organizationId: String?
    let user: UserDTO?
}

final class AuthService: Sendable {
    private let apiClient: APIClient

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func login(email: String, password: String) async throws -> LoginResponse {
        try await apiClient.post("/auth/login", body: LoginRequest(email: email, password: password))
    }

    func logout() async throws {
        try await apiClient.postNoResponse("/auth/logout")
    }

    func me() async throws -> UserDTO {
        let response: APIResponse<UserDTO> = try await apiClient.get("/auth/me")
        return response.data
    }

    func startDeviceLogin(deviceName: String) async throws -> DeviceStartResponse {
        let response: APIResponse<DeviceStartResponse> = try await apiClient.post("/auth/device/start", body: DeviceStartRequest(deviceName: deviceName))
        return response.data
    }

    /// Returns nil while the browser has not approved yet.
    func pollDeviceLogin(code: String) async throws -> DevicePollResponse? {
        do {
            let response: APIResponse<DevicePollResponse> = try await apiClient.get("/auth/device/\(code)")
            return response.data
        } catch APIError.httpError(let status, _) where status == 202 {
            return nil
        }
    }

    func organizations() async throws -> [OrganizationDTO] {
        let response: APIResponse<[OrganizationDTO]> = try await apiClient.get("/organizations")
        return response.data
    }
}
