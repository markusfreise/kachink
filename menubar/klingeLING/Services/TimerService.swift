import Foundation

struct UpdateTimeEntryRequest: Encodable {
    let stoppedAt: String?
    let description: String?

    init(stoppedAt: Date? = nil, description: String? = nil) {
        self.stoppedAt = stoppedAt.map(DateParsing.iso8601String)
        self.description = description
    }

    enum CodingKeys: String, CodingKey { case stoppedAt, description }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        if let stoppedAt { try c.encode(stoppedAt, forKey: .stoppedAt) }
        if let description { try c.encode(description, forKey: .description) }
    }
}

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

    /// Stops a specific entry at a given moment (used by the idle watchdog).
    func stop(entryId: String, at date: Date) async throws -> TimeEntryDTO {
        let response: APIResponse<TimeEntryDTO> = try await apiClient.put("/time-entries/\(entryId)", body: UpdateTimeEntryRequest(stoppedAt: date))
        return response.data
    }

    func updateDescription(entryId: String, description: String) async throws -> TimeEntryDTO {
        let response: APIResponse<TimeEntryDTO> = try await apiClient.put("/time-entries/\(entryId)", body: UpdateTimeEntryRequest(description: description))
        return response.data
    }

    func fetchRunning() async throws -> TimeEntryDTO? {
        let response: APIResponse<TimeEntryDTO?> = try await apiClient.get("/time-entries/running")
        return response.data
    }

    func fetchRecent(limit: Int = 6) async throws -> [TimeEntryDTO] {
        let response: APIResponse<[TimeEntryDTO]> = try await apiClient.get("/time-entries", query: ["per_page": "\(limit)", "sort": "-started_at", "filter[is_running]": "0"])
        return response.data
    }

    func fetchProjects() async throws -> [ProjectDTO] {
        let response: APIResponse<[ProjectDTO]> = try await apiClient.get("/projects", query: ["filter[is_active]": "1", "per_page": "200", "sort": "name"])
        return response.data
    }

    func fetchTasks() async throws -> [TaskDTO] {
        let response: APIResponse<[TaskDTO]> = try await apiClient.get("/tasks")
        return response.data
    }
}
