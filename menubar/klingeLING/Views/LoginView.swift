import SwiftUI

struct LoginView: View {
    @Environment(AppState.self) private var appState
    @State private var vm = LoginViewModel()

    var body: some View {
        VStack(spacing: 16) {
            Image(systemName: "clock.fill")
                .font(.system(size: 32))
                .foregroundStyle(.blue)

            Text("kaching.")
                .font(.title2.bold())

            Text("Paste your API token to connect")
                .font(.caption)
                .foregroundStyle(.secondary)

            VStack(alignment: .leading, spacing: 8) {
                TextField("Server URL", text: $vm.serverURL)
                    .textFieldStyle(.roundedBorder)
                    .font(.caption)

                SecureField("API Token", text: $vm.token)
                    .textFieldStyle(.roundedBorder)
                    .onSubmit { Task { await vm.login(appState: appState) } }
            }

            if let error = vm.error {
                Text(error)
                    .font(.caption)
                    .foregroundStyle(.red)
            }

            Button {
                Task { await vm.login(appState: appState) }
            } label: {
                if vm.isLoading {
                    ProgressView()
                        .controlSize(.small)
                        .frame(maxWidth: .infinity)
                } else {
                    Text("Connect")
                        .frame(maxWidth: .infinity)
                }
            }
            .buttonStyle(.borderedProminent)
            .disabled(vm.isLoading || vm.token.isEmpty)

            Text("Generate a token in the web app under Settings → API Tokens")
                .font(.caption2)
                .foregroundStyle(.tertiary)
                .multilineTextAlignment(.center)
        }
        .padding(20)
    }
}
