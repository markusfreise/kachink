import Foundation

struct TaskDTO: Codable, Identifiable, Sendable {
    let id: String
    let name: String
    let isActive: Bool
}
