import Foundation

struct ProjectDTO: Codable, Identifiable, Sendable {
    let id: String
    let clientId: String
    let name: String
    let slug: String
    let color: String
    let isBillable: Bool
    let isActive: Bool
    let client: ClientDTO?
    let budgetHours: Double?
    let hourlyRate: Double?
    let totalTrackedHours: Double?
}

struct ClientDTO: Codable, Identifiable, Sendable {
    let id: String
    let name: String
    let slug: String
    let color: String
    let isActive: Bool
}
