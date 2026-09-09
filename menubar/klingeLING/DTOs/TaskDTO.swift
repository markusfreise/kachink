import Foundation

struct TaskDTO: Codable, Identifiable, Sendable, Hashable {
    let id: String
    let name: String
    let isActive: Bool?

    static func == (lhs: TaskDTO, rhs: TaskDTO) -> Bool { lhs.id == rhs.id }
    func hash(into hasher: inout Hasher) { hasher.combine(id) }
}
