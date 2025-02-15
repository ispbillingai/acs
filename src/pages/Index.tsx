
import { DeviceStats } from "@/components/DeviceStats";
import { DeviceCard } from "@/components/DeviceCard";

// Mock data - replace with actual API calls
const mockDevices = [
  {
    id: "1",
    serialNumber: "HW123456789",
    model: "Huawei EchoLife HG8245H",
    status: "online",
    lastContact: "2024-02-20 15:30:00",
    ipAddress: "192.168.1.100",
  },
  {
    id: "2",
    serialNumber: "HW987654321",
    model: "Huawei EchoLife HG8245Q",
    status: "offline",
    lastContact: "2024-02-20 12:15:00",
    ipAddress: "192.168.1.101",
  },
  {
    id: "3",
    serialNumber: "HW456789123",
    model: "Huawei EchoLife HG8245U",
    status: "provisioning",
    lastContact: "2024-02-20 14:45:00",
    ipAddress: "192.168.1.102",
  },
] as const;

const Index = () => {
  return (
    <div className="min-h-screen bg-background p-6">
      <div className="max-w-7xl mx-auto space-y-8">
        <div className="space-y-2">
          <h1 className="text-3xl font-semibold tracking-tight">ACS Dashboard</h1>
          <p className="text-muted-foreground">
            Monitor and manage your TR-069 devices
          </p>
        </div>

        <DeviceStats />

        <div>
          <h2 className="text-xl font-semibold mb-4">Connected Devices</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {mockDevices.map((device) => (
              <DeviceCard key={device.id} device={device} />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Index;
