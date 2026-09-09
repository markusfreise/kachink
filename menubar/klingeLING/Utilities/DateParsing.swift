import Foundation

enum DateParsing {
    private static let laravelFormatter: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(secondsFromGMT: 0)
        f.dateFormat = "yyyy-MM-dd'T'HH:mm:ss.SSSSSSXXXXX"
        return f
    }()

    private static let iso8601Fractional: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    private static let iso8601: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    /// Parses Laravel's default JSON date ("2026-09-09T10:00:00.000000Z") and plain ISO 8601.
    static func parse(_ string: String) -> Date? {
        laravelFormatter.date(from: string)
            ?? iso8601Fractional.date(from: string)
            ?? iso8601.date(from: string)
    }

    static func iso8601String(_ date: Date) -> String {
        iso8601.string(from: date)
    }
}
