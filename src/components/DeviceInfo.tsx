
import { Card } from "@/components/ui/card";
import { CircleIcon } from "lucide-react";

interface DeviceInfoProps {
  device: {
    status: string;
    manufacturer: string;
    model: string;
    serialNumber: string;
    softwareVersion: string;
    hardwareVersion: string;
    ipAddress: string;
    lastContact: string;
    connectedClients: number;
    uptime: string;
  };
}

export const DeviceInfo = ({ device }: DeviceInfoProps) => {
  const getStatusColor = (status: string) => {
    switch (status) {
      case "online":
        return "text-success-dark";
      case "offline":
        return "text-error-dark";
      default:
        return "text-warning-dark";
    }
  };

  const infoItems = [
    { label: "Manufacturer", value: device.manufacturer },
    { label: "Model", value: device.model },
    { label: "Serial Number", value: device.serialNumber },
    { label: "Software Version", value: device.softwareVersion },
    { label: "Hardware Version", value: device.hardwareVersion },
    { label: "IP Address", value: device.ipAddress },
    { label: "Last Contact", value: device.lastContact },
    { label: "Connected Clients", value: device.connectedClients },
    { label: "Uptime", value: device.uptime },
  ];

  return (
    <Card className="p-6">
      <div className="flex items-center gap-2 mb-6">
        <CircleIcon className={`h-3 w-3 ${getStatusColor(device.status)}`} />
        <span className="font-medium capitalize">{device.status}</span>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {infoItems.map((item) => (
          <div key={item.label} className="space-y-1">
            <p className="text-sm text-muted-foreground">{item.label}</p>
            <p className="font-medium">{item.value}</p>
          </div>
        ))}
      </div>
    </Card>
  );
};
