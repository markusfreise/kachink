import Foundation

struct APIResponse<T: Codable & Sendable>: Codable, Sendable {
    let data: T
    let meta: PaginationMeta?

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        data = try container.decode(T.self, forKey: .data)
        meta = try container.decodeIfPresent(PaginationMeta.self, forKey: .meta)
    }

    private enum CodingKeys: String, CodingKey {
        case data, meta
    }
}

struct PaginationMeta: Codable, Sendable {
    let currentPage: Int
    let lastPage: Int
    let perPage: Int
    let total: Int
}
