import SwiftUI

struct StatusDotView: View {
    let isRunning: Bool
    @State private var isPulsing = false

    var body: some View {
        Circle()
            .fill(isRunning ? .green : .gray)
            .frame(width: 10, height: 10)
            .opacity(isRunning && isPulsing ? 0.5 : 1.0)
            .animation(
                isRunning
                    ? .easeInOut(duration: 1.0).repeatForever(autoreverses: true)
                    : .default,
                value: isPulsing
            )
            .onAppear { isPulsing = isRunning }
            .onChange(of: isRunning) { _, running in
                isPulsing = running
            }
    }
}
