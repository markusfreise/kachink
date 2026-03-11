import Foundation
import Network

final class SyncService: @unchecked Sendable {
    private let apiClient: APIClient
    private let monitor = NWPathMonitor()
    private let monitorQueue = DispatchQueue(label: "network-monitor")

    init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    func startMonitoring(onStatusChange: @escaping @Sendable (Bool) -> Void) {
        monitor.pathUpdateHandler = { path in
            onStatusChange(path.status == .satisfied)
        }
        monitor.start(queue: monitorQueue)
    }

    func stopMonitoring() {
        monitor.cancel()
    }
}
