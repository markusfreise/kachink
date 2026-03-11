import Foundation

final class TimerService: Sendable {
    private let apiClient: APIClient

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func startTimer(request: StartTimerRequest) async throws -> TimeEntryDTO {
        let response: APIResponse<TimeEntryDTO> = try await apiClient.post("/time-entries/start", body: request)
        return response.data
    }

    func stopTimer() async throws -> TimeEntryDTO {
        let response: APIResponse<TimeEntryDTO> = try await apiClient.post("/time-entries/stop")
        return response.data
    }

    func fetchRunning() async throws -> TimeEntryDTO? {
        let response: APIResponse<TimeEntryDTO?> = try await apiClient.get("/time-entries/running")
        return response.data
    }

    func fetchRecent(limit: Int = 5) async throws -> [TimeEntryDTO] {
        let response: APIResponse<[TimeEntryDTO]> = try await apiClient.get("/time-entries?per_page=\(limit)&sort=-started_at")
        return response.data
    }
}
