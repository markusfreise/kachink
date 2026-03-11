import Foundation

struct TimeEntryDTO: Codable, Identifiable, Sendable {
    let id: String
    let userId: String
    let projectId: String
    let taskId: String?
    let description: String?
    let startedAt: String
    let stoppedAt: String?
    let durationSeconds: Int?
    let durationHuman: String?
    let isBillable: Bool
    let isRunning: Bool
    let source: String
    let user: UserDTO?
    let project: ProjectDTO?
    let task: TaskDTO?
    let createdAt: String
    let updatedAt: String
}

struct StartTimerRequest: Codable, Sendable {
    let projectId: String
    let taskId: String?
    let description: String?
    let isBillable: Bool?
    let source: String

    init(projectId: String, taskId: String? = nil, description: String? = nil, isBillable: Bool? = nil) {
        self.projectId = projectId
        self.taskId = taskId
        self.description = description
        self.isBillable = isBillable
        self.source = "menubar"
    }
}
