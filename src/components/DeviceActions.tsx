
import { Button } from "@/components/ui/button";
import { DownloadIcon, RefreshCwIcon, PowerIcon } from "lucide-react";
import { Device } from "@/types";

export interface DeviceActionsProps {
  device: Device;
  onRefresh?: () => void;
}

export const DeviceActions = ({ device, onRefresh }: DeviceActionsProps) => {
  const handleReboot = () => {
    if (window.confirm(`Are you sure you want to reboot device ${device.serialNumber}?`)) {
      console.log("Rebooting device:", device.id);
      // In a real application, this would be an API call
      alert("Reboot command sent to device");
    }
  };

  const handleRefresh = () => {
    if (onRefresh) {
      onRefresh();
    } else {
      console.log("Refreshing device data:", device.id);
      // In a real application, this would refresh the device data
      window.location.reload();
    }
  };

  const handleBackup = () => {
    console.log("Backing up device configuration:", device.id);
    // In a real application, this would initiate a backup download
    alert("Backup initiated, download will start shortly");
  };

  return (
    <div className="flex space-x-2">
      <Button variant="outline" size="sm" onClick={handleRefresh}>
        <RefreshCwIcon className="mr-2 h-4 w-4" />
        Refresh
      </Button>
      <Button variant="outline" size="sm" onClick={handleBackup}>
        <DownloadIcon className="mr-2 h-4 w-4" />
        Backup
      </Button>
      <Button variant="outline" size="sm" onClick={handleReboot}>
        <PowerIcon className="mr-2 h-4 w-4" />
        Reboot
      </Button>
    </div>
  );
};
