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

    func organizations() async throws -> [OrganizationDTO] {
        let response: APIResponse<[OrganizationDTO]> = try await apiClient.get("/organizations")
        return response.data
    }
}
