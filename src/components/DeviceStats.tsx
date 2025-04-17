
import { Card } from "@/components/ui/card";
import { CircleIcon, WifiIcon, ClockIcon, CpuIcon, RouterIcon, PlugIcon } from "lucide-react";
import { Device } from "@/types";
import { useEffect, useState } from "react";

interface DeviceStatsProps {
  device: Device;
}

export const DeviceStats = ({ device }: DeviceStatsProps) => {
  const [currentStatus, setCurrentStatus] = useState(device.status || 'unknown');
  const [lastChecked, setLastChecked] = useState<string | null>(null);

  // Log device data for debugging
  console.log("DeviceStats received device:", device);

  // Check actual device status in case the displayed status is incorrect
  useEffect(() => {
    const checkDeviceStatus = async () => {
      if (!device.id) {
        console.log("No device ID available, skipping status check");
        return;
      }
      
      try {
        console.log("Checking status for device ID:", device.id);
        const formData = new FormData();
        formData.append('device_id', device.id.toString());
        formData.append('action', 'check_connection');
        
        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        console.log("Device status check result:", result);
        
        if (result.success && result.connection_status) {
          setCurrentStatus(result.connection_status.success ? 'online' : 'offline');
          setLastChecked(new Date().toLocaleString());
        }
      } catch (error) {
        console.error("Error checking device status:", error);
      }
    };
    
    // Check status when component mounts
    checkDeviceStatus();
    
    // Set up an interval to periodically check status (every 30 seconds)
    const interval = setInterval(checkDeviceStatus, 30000);
    
    return () => clearInterval(interval);
  }, [device.id]);

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

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <Card className="p-4 bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-sm">
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center">
            <CircleIcon className={`h-3 w-3 mr-2 ${getStatusColor(currentStatus)}`} />
            <h3 className="text-sm font-medium text-gray-500">Status</h3>
          </div>
          {lastChecked && (
            <span className="text-xs text-gray-400">
              Last checked: {lastChecked}
            </span>
          )}
        </div>
        <p className={`text-2xl font-bold ${getStatusColor(currentStatus)}`}>
          {currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1)}
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
        <p className="text-2xl font-bold">{device.connectedClients || '0'}</p>
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
