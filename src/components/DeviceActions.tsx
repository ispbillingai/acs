
import { Button } from "@/components/ui/button";
import { DownloadIcon, RefreshCwIcon, PowerIcon, SignalIcon } from "lucide-react";
import { Device } from "@/types";
import { toast } from "sonner";

export interface DeviceActionsProps {
  device: Device;
  onRefresh?: () => void;
  onRefreshOptical?: () => void;
}

export const DeviceActions = ({ device, onRefresh, onRefreshOptical }: DeviceActionsProps) => {
  const handleReboot = () => {
    if (window.confirm(`Are you sure you want to reboot device ${device.serialNumber}?`)) {
      console.log("Rebooting device:", device.id);
      // In a real application, this would be an API call
      toast.success("Reboot command sent to device");
    }
  };

  const handleRefresh = () => {
    if (onRefresh) {
      onRefresh();
      toast.success("Device refresh initiated");
    } else {
      console.log("Refreshing device data:", device.id);
      // In a real application, this would refresh the device data
      window.location.reload();
    }
  };

  const handleRefreshOptical = () => {
    if (onRefreshOptical) {
      onRefreshOptical();
      toast.success("Optical readings refresh initiated");
    } else {
      console.log("Refreshing optical readings:", device.id);
      
      // Make direct API call if no callback provided
      fetch(`/backend/api/refresh_optical.php?id=${device.id}`)
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            toast.success("Optical readings refresh initiated");
            setTimeout(() => window.location.reload(), 1500);
          } else {
            toast.error(`Failed to refresh optical readings: ${data.message}`);
          }
        })
        .catch(error => {
          console.error("Error refreshing optical readings:", error);
          toast.error("Error refreshing optical readings");
        });
    }
  };

  const handleBackup = () => {
    console.log("Backing up device configuration:", device.id);
    // In a real application, this would initiate a backup download
    toast.success("Backup initiated, download will start shortly");
  };

  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="outline" size="sm" onClick={handleRefresh}>
        <RefreshCwIcon className="mr-2 h-4 w-4" />
        Refresh
      </Button>
      <Button variant="outline" size="sm" onClick={handleRefreshOptical}>
        <SignalIcon className="mr-2 h-4 w-4" />
        Refresh Optical
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
