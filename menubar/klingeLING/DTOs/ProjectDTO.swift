import Foundation

struct ProjectDTO: Codable, Identifiable, Sendable, Hashable {
    let id: String
    let clientId: String?
    let name: String
    let color: String
    let isBillable: Bool
    let isActive: Bool
    let client: ClientDTO?

    static func == (lhs: ProjectDTO, rhs: ProjectDTO) -> Bool { lhs.id == rhs.id }
    func hash(into hasher: inout Hasher) { hasher.combine(id) }
}

struct ClientDTO: Codable, Identifiable, Sendable {
    let id: String
    let name: String
    let color: String?
}
