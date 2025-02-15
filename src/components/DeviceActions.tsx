
import { Button } from "@/components/ui/button";
import { RefreshCw, RotateCw, Settings } from "lucide-react";
import { useToast } from "@/components/ui/use-toast";

interface DeviceActionsProps {
  device: {
    id: string;
    serialNumber: string;
  };
}

export const DeviceActions = ({ device }: DeviceActionsProps) => {
  const { toast } = useToast();

  const handleReboot = () => {
    toast({
      title: "Reboot Initiated",
      description: `Device ${device.serialNumber} is rebooting...`,
    });
  };

  const handleFactoryReset = () => {
    toast({
      title: "Factory Reset",
      description: "This feature is coming soon...",
      variant: "destructive",
    });
  };

  const handleRefreshParams = () => {
    toast({
      title: "Refreshing Parameters",
      description: "Fetching latest parameter values...",
    });
  };

  return (
    <div className="flex items-center gap-2">
      <Button
        variant="outline"
        size="sm"
        className="flex items-center gap-2"
        onClick={handleRefreshParams}
      >
        <RefreshCw className="h-4 w-4" />
        Refresh
      </Button>
      <Button
        variant="outline"
        size="sm"
        className="flex items-center gap-2"
        onClick={handleReboot}
      >
        <RotateCw className="h-4 w-4" />
        Reboot
      </Button>
      <Button
        variant="outline"
        size="sm"
        className="flex items-center gap-2"
        onClick={handleFactoryReset}
      >
        <Settings className="h-4 w-4" />
        Factory Reset
      </Button>
    </div>
  );
};
