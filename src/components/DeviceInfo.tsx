
import { Card } from "@/components/ui/card";
import { CircleIcon, WifiIcon, ClockIcon, HardDriveIcon, ServerIcon, CpuIcon, RouterIcon, AlertTriangleIcon } from "lucide-react";
import { Device } from "@/types";

interface DeviceInfoProps {
  device: Device;
}

export const DeviceInfo = ({ device }: DeviceInfoProps) => {
  // Add console logging to debug device info
  console.log("DeviceInfo received device data:", device);
  
  const getStatusColor = (status: string) => {
    switch (status) {
      case "online":
        return "text-green-600";
      case "offline":
        return "text-red-600";
      default:
        return "text-orange-600";
    }
  };

  // Default to Huawei if manufacturer is missing or "Unknown"
  const manufacturer = (!device.manufacturer || device.manufacturer === "Unknown") ? "Huawei" : device.manufacturer;

  const infoItems = [
    { 
      label: "Manufacturer", 
      value: manufacturer,
      icon: <ServerIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Model", 
      value: device.model,
      icon: <CpuIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Serial Number", 
      value: device.serialNumber,
      icon: <ServerIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Software Version", 
      value: device.softwareVersion,
      icon: <HardDriveIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Hardware Version", 
      value: device.hardwareVersion,
      icon: <HardDriveIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "IP Address", 
      value: device.ipAddress,
      icon: <ServerIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "SSID", 
      value: device.ssid,
      icon: <WifiIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Last Contact", 
      value: device.lastContact ? new Date(device.lastContact).toLocaleString() : "N/A",
      icon: <ClockIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Connected Clients", 
      value: device.connectedClients || "0",
      icon: <RouterIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Uptime", 
      value: device.uptime,
      icon: <ClockIcon className="h-4 w-4 text-blue-500" />
    },
  ];

  return (
    <Card className="p-6 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-md">
      <div className="flex items-center gap-2 mb-6">
        <CircleIcon className={`h-3 w-3 ${getStatusColor(device.status)}`} />
        <span className={`font-medium capitalize ${getStatusColor(device.status)}`}>
          {device.status}
        </span>
      </div>

      {device && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {infoItems.map((item) => (
            <div key={item.label} className="space-y-1 bg-white p-3 rounded-lg border border-blue-50 shadow-sm hover:shadow transition-shadow">
              <p className="text-sm text-blue-500 font-medium">{item.label}</p>
              <div className="font-medium flex items-center gap-2">
                {item.icon}
                <span className="text-gray-800">
                  {item.value !== undefined && item.value !== null ? item.value : "N/A"}
                </span>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Error logs section */}
      <div className="mt-6 bg-red-50 p-4 rounded-lg border border-red-100">
        <div className="flex items-center text-red-700 mb-2">
          <AlertTriangleIcon className="h-5 w-5 mr-2" />
          <h3 className="font-semibold">Debug Information</h3>
        </div>
        <div className="bg-white p-3 rounded border border-red-100 text-sm font-mono text-gray-700 max-h-60 overflow-auto">
          <p>Device data received: {JSON.stringify(device, null, 2)}</p>
          <p className="mt-2 text-red-600">Note: If manufacturer is "Unknown" or missing, check if backend is properly updating device.manufacturer.</p>
        </div>
      </div>
    </Card>
  );
};
