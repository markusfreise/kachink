import SwiftUI

struct SettingsView: View {
    @Environment(AppState.self) private var appState

    var body: some View {
        Form {
            Section("Account") {
                if let user = appState.currentUser {
                    LabeledContent("Name", value: user.name)
                    LabeledContent("Email", value: user.email)
                    LabeledContent("Role", value: user.role)
                } else {
                    Text("Not connected")
                        .foregroundStyle(.secondary)
                }

                Button("Log out", role: .destructive) {
                    appState.logout()
                }
            }

            Section("Server") {
                LabeledContent("URL", value: appState.apiClient.baseURL.absoluteString)
            }

            Section("About") {
                LabeledContent("Version", value: "1.0.0")
                LabeledContent("App", value: "klingeLING!")
            }
        }
        .formStyle(.grouped)
        .frame(width: 400, height: 300)
    }
}
