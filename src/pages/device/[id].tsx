
import { useParams } from "react-router-dom";
import { Card } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceParameters } from "@/components/DeviceParameters";
import { DeviceActions } from "@/components/DeviceActions";

const DevicePage = () => {
  const { id } = useParams();

  // This will be replaced with actual API call
  const device = {
    id: "1",
    serialNumber: "HW123456789",
    model: "Huawei EchoLife HG8245H",
    status: "online",
    lastContact: "2024-02-20 15:30:00",
    ipAddress: "192.168.1.100",
    manufacturer: "Huawei",
    softwareVersion: "V1.2.3",
    hardwareVersion: "Rev.A",
    connectedClients: 5,
    uptime: "5 days 2 hours",
  };

  return (
    <div className="min-h-screen bg-background p-6">
      <div className="max-w-7xl mx-auto space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-semibold tracking-tight mb-1">
              {device.model}
            </h1>
            <p className="text-muted-foreground">{device.serialNumber}</p>
          </div>
          <DeviceActions device={device} />
        </div>

        <Tabs defaultValue="info" className="w-full">
          <TabsList>
            <TabsTrigger value="info">Device Info</TabsTrigger>
            <TabsTrigger value="parameters">Parameters</TabsTrigger>
            <TabsTrigger value="logs">Logs</TabsTrigger>
          </TabsList>
          <TabsContent value="info">
            <DeviceInfo device={device} />
          </TabsContent>
          <TabsContent value="parameters">
            <DeviceParameters deviceId={device.id} />
          </TabsContent>
          <TabsContent value="logs">
            <Card className="p-6">
              <p className="text-muted-foreground">Coming soon...</p>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
};

export default DevicePage;
