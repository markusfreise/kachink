import Foundation

final class ProjectService: Sendable {
    private let apiClient: APIClient

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func fetchProjects() async throws -> [ProjectDTO] {
        let response: APIResponse<[ProjectDTO]> = try await apiClient.get("/projects?filter[is_active]=true&per_page=200")
        return response.data
    }

    func fetchTasks() async throws -> [TaskDTO] {
        let response: APIResponse<[TaskDTO]> = try await apiClient.get("/tasks")
        return response.data
    }
}
