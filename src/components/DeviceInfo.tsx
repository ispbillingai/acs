import { Card } from "@/components/ui/card";
import { CircleIcon, WifiIcon, ClockIcon, HardDriveIcon, ServerIcon, CpuIcon, RouterIcon, ZapIcon, SignalIcon } from "lucide-react";
import { Device } from "@/types";
import { DebugLogger } from "@/components/DebugLogger";
import { useState } from "react";

interface DeviceInfoProps {
  device: Device;
}

export const DeviceInfo = ({ device }: DeviceInfoProps) => {
  const [showDebug, setShowDebug] = useState(false);
  
  // Log device info for debugging
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

  const infoItems = [
    { 
      label: "Manufacturer", 
      value: device.manufacturer,
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
      value: device.connectedDevices?.toString() || "0",
      icon: <RouterIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "Uptime", 
      value: device.uptime,
      icon: <ClockIcon className="h-4 w-4 text-blue-500" />
    },
    { 
      label: "TX Power", 
      value: device.txPower || "N/A",
      icon: <SignalIcon className="h-4 w-4 text-green-600" />
    },
    { 
      label: "RX Power", 
      value: device.rxPower || "N/A",
      icon: <ZapIcon className="h-4 w-4 text-amber-500" />
    },
  ];

  return (
    <Card className="p-6 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-md">
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-2">
          <CircleIcon className={`h-3 w-3 ${getStatusColor(device.status)}`} />
          <span className={`font-medium capitalize ${getStatusColor(device.status)}`}>
            {device.status}
          </span>
        </div>
        <button 
          onClick={() => setShowDebug(!showDebug)}
          className="text-xs bg-blue-50 hover:bg-blue-100 text-blue-800 px-2 py-1 rounded border border-blue-200"
        >
          {showDebug ? "Hide Raw Data" : "Show Raw Data"}
        </button>
      </div>

      {device && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
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

      {/* Show debug info when requested */}
      {showDebug && (
        <DebugLogger data={device} title="Device Raw Data" />
      )}
    </Card>
  );
};
