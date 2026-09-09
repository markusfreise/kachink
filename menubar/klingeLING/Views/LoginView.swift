import SwiftUI

struct LoginView: View {
    @Environment(AppState.self) private var appState
    @State private var vm = LoginViewModel()

    var body: some View {
        VStack(spacing: 14) {
            Image(systemName: "clock.fill")
                .font(.system(size: 28))
                .foregroundStyle(.tint)

            Text("Kachink")
                .font(.title3.bold())

            VStack(alignment: .leading, spacing: 6) {
                Text("Server")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                TextField("kachink.croeso.de", text: $vm.serverURL)
                    .textFieldStyle(.roundedBorder)
                    .disabled(vm.isWaiting)
                    .onSubmit { if vm.canSubmit { vm.signInWithBrowser(appState: appState) } }
            }

            if vm.isWaiting {
                VStack(spacing: 8) {
                    ProgressView().controlSize(.small)
                    Text("Waiting for approval in your browser...")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                    Button("Cancel") { vm.cancel() }
                        .buttonStyle(.plain)
                        .font(.caption)
                }
            } else {
                Button {
                    vm.signInWithBrowser(appState: appState)
                } label: {
                    Label("Sign in with browser", systemImage: "safari")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .disabled(!vm.canSubmit)

                Text("Opens the web app, where you allow this Mac. No password is entered here.")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .multilineTextAlignment(.center)
            }

            if let error = vm.error {
                Text(error)
                    .font(.caption)
                    .foregroundStyle(.red)
                    .multilineTextAlignment(.center)
            }

            HStack {
                Spacer()
                Button("Quit") { NSApplication.shared.terminate(nil) }
                    .buttonStyle(.plain)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(20)
    }
}
