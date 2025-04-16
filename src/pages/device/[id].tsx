
import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { useToast } from "@/hooks/use-toast";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceStats } from "@/components/DeviceStats";
import { DeviceActions } from "@/components/DeviceActions";
import { DeviceParameters } from "@/components/DeviceParameters";
import { ConnectedHosts } from "@/components/ConnectedHosts";
import { Clock, AlertCircle, RefreshCw } from "lucide-react";
import { config } from "@/config";

interface Device {
  id: string;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: string;
  lastContact: string;
  ipAddress: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  ssid?: string;
  connectedClients?: number;
}

export default function DevicePage() {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [lastRefresh, setLastRefresh] = useState<string>(
    new Date().toLocaleTimeString()
  );
  const { toast } = useToast();
  const [refreshInterval, setRefreshInterval] = useState<number | null>(
    config.ACS_SETTINGS.REFRESH_INTERVAL
  );
  const [onlineThreshold] = useState<number>(
    config.ACS_SETTINGS.ONLINE_THRESHOLD
  );

  const fetchDevice = async () => {
    try {
      setLoading(true);
      const response = await fetch(`/backend/api/devices.php?id=${id}`);
      if (!response.ok) {
        throw new Error("Failed to fetch device");
      }
      const data = await response.json();
      if (data.success && data.device) {
        setDevice(data.device);
        setError(null);
      } else {
        setError(data.message || "Failed to load device data");
      }
    } catch (err) {
      setError("An error occurred while fetching device data");
      console.error(err);
    } finally {
      setLoading(false);
      setLastRefresh(new Date().toLocaleTimeString());
    }
  };

  useEffect(() => {
    if (id) {
      fetchDevice();
    }

    let intervalId: number | null = null;

    if (refreshInterval) {
      intervalId = window.setInterval(() => {
        fetchDevice();
      }, refreshInterval);
    }

    return () => {
      if (intervalId !== null) {
        clearInterval(intervalId);
      }
    };
  }, [id, refreshInterval]);

  const handleRefresh = () => {
    toast({
      title: "Refreshing device data",
      description: "Fetching the latest information from the server.",
      duration: 3000,
    });
    fetchDevice();
  };

  const toggleAutoRefresh = () => {
    if (refreshInterval) {
      setRefreshInterval(null);
      toast({
        title: "Auto-refresh disabled",
        description: "You will need to manually refresh the device data.",
        variant: "default",
      });
    } else {
      setRefreshInterval(config.ACS_SETTINGS.REFRESH_INTERVAL);
      toast({
        title: "Auto-refresh enabled",
        description: `Device data will refresh every ${
          config.ACS_SETTINGS.REFRESH_INTERVAL / 1000
        } seconds.`,
        variant: "default",
      });
    }
  };

  const isDeviceOnline = (device: Device): boolean => {
    if (!device || !device.lastContact) return false;
    const lastContactTime = new Date(device.lastContact).getTime();
    const currentTime = new Date().getTime();
    return currentTime - lastContactTime < onlineThreshold;
  };

  if (loading && !device) {
    return (
      <div className="container mx-auto py-8">
        <Card>
          <CardContent className="p-8">
            <div className="text-center">Loading device information...</div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (error || !device) {
    return (
      <div className="container mx-auto py-8">
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>
            {error || "Device not found"}
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  return (
    <div className="container mx-auto py-8">
      <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-3xl font-bold">{device.model}</h1>
          <p className="text-gray-500">
            Serial: {device.serialNumber} | IP: {device.ipAddress}
          </p>
        </div>
        <div className="flex gap-2 items-center">
          <div className="text-sm text-gray-500 flex items-center">
            <Clock className="mr-1 h-3 w-3" />
            Last updated: {lastRefresh}
          </div>
          <Button
            size="sm"
            variant="outline"
            onClick={toggleAutoRefresh}
            className={refreshInterval ? "bg-green-50" : ""}
          >
            {refreshInterval ? "Auto-refresh On" : "Auto-refresh Off"}
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={handleRefresh}
            className="animate-in"
          >
            <RefreshCw className="mr-1 h-4 w-4" />
            Refresh
          </Button>
        </div>
      </div>

      {!isDeviceOnline(device) && (
        <Alert variant="destructive" className="mb-6">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>Device Offline</AlertTitle>
          <AlertDescription>
            This device has not been seen since{" "}
            {new Date(device.lastContact).toLocaleString()}. Some information may
            be outdated.
          </AlertDescription>
        </Alert>
      )}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <DeviceInfo device={device} />
        <DeviceStats device={device} />
        <DeviceActions device={device} onRefresh={fetchDevice} />
      </div>

      <Tabs defaultValue="parameters" className="w-full">
        <TabsList className="grid w-full md:w-[600px] grid-cols-2">
          <TabsTrigger value="parameters">Parameters</TabsTrigger>
          <TabsTrigger value="hosts">Connected Hosts</TabsTrigger>
        </TabsList>
        <TabsContent value="parameters" className="mt-6">
          <DeviceParameters deviceId={device.id} />
        </TabsContent>
        <TabsContent value="hosts" className="mt-6">
          <ConnectedHosts deviceId={device.id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
