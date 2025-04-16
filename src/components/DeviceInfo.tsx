
import { Card } from "@/components/ui/card";
import { CircleIcon, WifiIcon, ClockIcon, HardDriveIcon } from "lucide-react";
import { Device } from "@/types";

interface DeviceInfoProps {
  device: Device;
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
    { 
      label: "Software Version", 
      value: device.softwareVersion,
      icon: device.softwareVersion ? <HardDriveIcon className="h-4 w-4 text-blue-500" /> : null 
    },
    { label: "Hardware Version", value: device.hardwareVersion },
    { label: "IP Address", value: device.ipAddress },
    { 
      label: "SSID", 
      value: device.ssid,
      icon: device.ssid ? <WifiIcon className="h-4 w-4 text-blue-500" /> : null 
    },
    { label: "Last Contact", value: device.lastContact },
    { label: "Connected Clients", value: device.connectedClients },
    { 
      label: "Uptime", 
      value: device.uptime,
      icon: device.uptime ? <ClockIcon className="h-4 w-4 text-blue-500" /> : null 
    },
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
            <div className="font-medium flex items-center gap-1">
              {item.icon}
              {item.value !== undefined && item.value !== null ? item.value : "N/A"}
            </div>
          </div>
        ))}
      </div>
    </Card>
  );
};
