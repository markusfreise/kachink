import Foundation

/// UserDefaults-backed app preferences.
enum Preferences {
    private static let defaults = UserDefaults.standard

    enum Key {
        static let serverURL = "serverURL"
        static let organizationId = "organizationId"
        static let idleThresholdMinutes = "idleThresholdMinutes"
        static let showSecondsInMenuBar = "showSecondsInMenuBar"
        static let lastProjectId = "lastProjectId"
        static let lastTaskId = "lastTaskId"
    }

    static var serverURL: String {
        get { defaults.string(forKey: Key.serverURL) ?? "" }
        set { defaults.set(newValue, forKey: Key.serverURL) }
    }

    static var organizationId: String? {
        get { defaults.string(forKey: Key.organizationId) }
        set { defaults.set(newValue, forKey: Key.organizationId) }
    }

    /// Minutes of inactivity before the idle watchdog asks what to do.
    static var idleThresholdMinutes: Int {
        get {
            let v = defaults.integer(forKey: Key.idleThresholdMinutes)
            return v == 0 ? 10 : v
        }
        set { defaults.set(newValue, forKey: Key.idleThresholdMinutes) }
    }

    static var showSecondsInMenuBar: Bool {
        get { defaults.bool(forKey: Key.showSecondsInMenuBar) }
        set { defaults.set(newValue, forKey: Key.showSecondsInMenuBar) }
    }

    static var lastProjectId: String? {
        get { defaults.string(forKey: Key.lastProjectId) }
        set { defaults.set(newValue, forKey: Key.lastProjectId) }
    }

    static var lastTaskId: String? {
        get { defaults.string(forKey: Key.lastTaskId) }
        set { defaults.set(newValue, forKey: Key.lastTaskId) }
    }
}
