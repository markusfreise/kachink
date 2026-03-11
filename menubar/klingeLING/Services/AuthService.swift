import Foundation

final class AuthService: Sendable {
    private let apiClient: APIClient

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func validateToken() async throws -> UserDTO {
        let response: APIResponse<UserDTO> = try await apiClient.get("/auth/me")
        return response.data
    }
}
