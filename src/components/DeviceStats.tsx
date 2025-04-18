
import { Card } from "@/components/ui/card";
import { CircleIcon, WifiIcon, ClockIcon, CpuIcon, RouterIcon, PlugIcon } from "lucide-react";
import { Device } from "@/types";

interface DeviceStatsProps {
  device: Device;
}

export const DeviceStats = ({ device }: DeviceStatsProps) => {
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

  // Format uptime from seconds to days, hours, minutes
  const formatUptime = (uptimeSeconds: string | undefined): string => {
    if (!uptimeSeconds) return 'N/A';
    
    const seconds = parseInt(uptimeSeconds, 10);
    if (isNaN(seconds)) return 'N/A';
    
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    
    if (days > 0) {
      return `${days}d ${hours}h ${minutes}m`;
    } else if (hours > 0) {
      return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
      return `${minutes}m`;
    } else {
      return `${seconds}s`;
    }
  };

  // Add console logs to debug the data we're receiving
  console.log("DeviceStats - Full device data:", device);
  console.log("DeviceStats - Connected devices value:", device.connectedDevices);

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <Card className="p-4 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-sm">
        <div className="flex items-center mb-2">
          <CircleIcon className={`h-3 w-3 mr-2 ${getStatusColor(device.status)}`} />
          <h3 className="text-sm font-medium text-gray-500">Status</h3>
        </div>
        <p className={`text-2xl font-bold ${getStatusColor(device.status)}`}>
          {device.status.charAt(0).toUpperCase() + device.status.slice(1)}
        </p>
      </Card>
      
      <Card className="p-4 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-sm">
        <div className="flex items-center mb-2">
          <WifiIcon className="h-4 w-4 mr-2 text-blue-500" />
          <h3 className="text-sm font-medium text-gray-500">SSID</h3>
        </div>
        <p className="text-2xl font-bold">{device.ssid || 'N/A'}</p>
      </Card>
      
      <Card className="p-4 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-sm">
        <div className="flex items-center mb-2">
          <RouterIcon className="h-4 w-4 mr-2 text-blue-500" />
          <h3 className="text-sm font-medium text-gray-500">Connected Clients</h3>
        </div>
        <p className="text-2xl font-bold">{device.connectedDevices || '0'}</p>
      </Card>
      
      <Card className="p-4 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-sm">
        <div className="flex items-center mb-2">
          <ClockIcon className="h-4 w-4 mr-2 text-blue-500" />
          <h3 className="text-sm font-medium text-gray-500">Uptime</h3>
        </div>
        <p className="text-2xl font-bold">{formatUptime(device.uptime)}</p>
      </Card>
    </div>
  );
};
