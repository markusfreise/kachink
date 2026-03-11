import Foundation

struct UserDTO: Codable, Identifiable, Sendable {
    let id: String
    let name: String
    let email: String
    let role: String
    let avatarUrl: String?
    let isActive: Bool
    let createdAt: String
}
