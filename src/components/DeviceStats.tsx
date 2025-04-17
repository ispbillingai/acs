
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
    const determineStatus = (lastContactTime: string | undefined): 'online' | 'offline' | 'unknown' => {
      if (!lastContactTime) return 'unknown';
      
      // Consider a device online if last contact was within the last 5 minutes
      // This is a realistic window for TR-069 devices that should check in regularly
      const lastContact = new Date(lastContactTime);
      const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000);
      
      console.log(`Last contact: ${lastContact}, Five minutes ago: ${fiveMinutesAgo}`);
      console.log(`Is device online? ${lastContact > fiveMinutesAgo}`);
      
      return lastContact > fiveMinutesAgo ? 'online' : 'offline';
    };
    
    // Initial status check based on device.lastContact
    if (device.lastContact) {
      const calculatedStatus = determineStatus(device.lastContact);
      setCurrentStatus(calculatedStatus);
      setLastChecked(new Date().toLocaleString());
      console.log(`Initial status check based on lastContact (${device.lastContact}): ${calculatedStatus}`);
    }
    
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
          const newStatus = result.connection_status.success ? 'online' : 'offline';
          setCurrentStatus(newStatus);
          setLastChecked(new Date().toLocaleString());
          
          console.log(`Status updated from API check: ${newStatus}`);
          console.log(`Last contact from API: ${result.connection_status.last_contact}`);
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
  }, [device.id, device.lastContact]);

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

  // Calculate how long ago the last contact was
  const getLastContactTime = (lastContact: string | undefined): string => {
    if (!lastContact) return 'Never';
    
    try {
      const lastContactDate = new Date(lastContact);
      const now = new Date();
      const diffMs = now.getTime() - lastContactDate.getTime();
      
      // Convert to appropriate time units
      const diffMins = Math.floor(diffMs / 60000);
      if (diffMins < 60) {
        return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
      }
      
      const diffHours = Math.floor(diffMins / 60);
      if (diffHours < 24) {
        return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
      }
      
      const diffDays = Math.floor(diffHours / 24);
      return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    } catch (e) {
      console.error("Error calculating last contact time:", e);
      return lastContact; // Fallback to raw timestamp
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
        {device.lastContact && (
          <p className="text-xs text-gray-500 mt-1">
            Last contact: {getLastContactTime(device.lastContact)}
            <span className="block mt-1 text-xs italic">
              ({new Date(device.lastContact).toLocaleString()})
            </span>
          </p>
        )}
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
