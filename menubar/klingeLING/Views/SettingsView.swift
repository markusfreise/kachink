import ServiceManagement
import SwiftUI

struct SettingsView: View {
    @Environment(AppState.self) private var appState
    @State private var idleMinutes = Preferences.idleThresholdMinutes
    @State private var showSeconds = Preferences.showSecondsInMenuBar
    @State private var launchAtLogin = SMAppService.mainApp.status == .enabled
    @State private var launchError: String?

    private let idleOptions = [5, 10, 15, 20, 30]

    var body: some View {
        Form {
            Section("Account") {
                if let user = appState.currentUser {
                    LabeledContent("Name", value: user.name)
                    LabeledContent("Email", value: user.email)
                } else {
                    Text("Not signed in").foregroundStyle(.secondary)
                }
                LabeledContent("Server", value: appState.apiClient.baseURL?.host() ?? "-")

                if appState.organizations.count > 1 {
                    Picker("Organization", selection: Binding(
                        get: { appState.currentOrganizationId ?? "" },
                        set: { appState.selectOrganization($0) }
                    )) {
                        ForEach(appState.organizations) { org in
                            Text(org.name).tag(org.id)
                        }
                    }
                } else if let org = appState.currentOrganization {
                    LabeledContent("Organization", value: org.name)
                }

                if appState.isAuthenticated {
                    Button("Sign out", role: .destructive) { appState.logout() }
                }
            }

            Section("Idle watchdog") {
                Picker("Ask after", selection: $idleMinutes) {
                    ForEach(idleOptions, id: \.self) { m in
                        Text("\(m) minutes").tag(m)
                    }
                }
                .onChange(of: idleMinutes) { _, value in Preferences.idleThresholdMinutes = value }
                Text("When a timer is running and you have been away this long, Kachink asks whether to keep, discard or stop at the idle time. Sleep and screen lock count as idle.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Section("Menu bar") {
                Toggle("Show seconds", isOn: $showSeconds)
                    .onChange(of: showSeconds) { _, value in Preferences.showSecondsInMenuBar = value }
                Toggle("Launch at login", isOn: $launchAtLogin)
                    .onChange(of: launchAtLogin) { _, value in toggleLaunchAtLogin(value) }
                if let launchError {
                    Text(launchError).font(.caption).foregroundStyle(.red)
                }
                LabeledContent("Start/stop shortcut", value: "Cmd+Shift+T")
            }

            Section("About") {
                LabeledContent("Version", value: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "-")
            }
        }
        .formStyle(.grouped)
        .frame(width: 440)
        .frame(minHeight: 420)
    }

    private func toggleLaunchAtLogin(_ enabled: Bool) {
        do {
            if enabled {
                try SMAppService.mainApp.register()
            } else {
                try SMAppService.mainApp.unregister()
            }
            launchError = nil
        } catch {
            launchError = error.localizedDescription
            launchAtLogin = SMAppService.mainApp.status == .enabled
        }
    }
}
