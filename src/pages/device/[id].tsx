
import { useParams } from "react-router-dom";
import { useState, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";

import { fetchDeviceById } from "@/utils/apiClient";
import { Card } from "@/components/ui/card";
import { DeviceStats } from "@/components/DeviceStats";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceActions } from "@/components/DeviceActions";
import { DeviceParameters } from "@/components/DeviceParameters";
import { OpticalReadings } from "@/components/OpticalReadings";
import { ConnectedHosts } from "@/components/ConnectedHosts";
import { DeviceConfiguration } from "@/components/DeviceConfiguration";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { CircleIcon } from "lucide-react";

const DeviceDetailsPage = () => {
  const { id } = useParams<{ id: string }>();
  const [refreshTrigger, setRefreshTrigger] = useState(0);
  const deviceId = id || "";

  // Fetch device data
  const {
    data: device,
    isLoading,
    isError,
    error,
    refetch,
  } = useQuery({
    queryKey: ["device", deviceId, refreshTrigger],
    queryFn: () => fetchDeviceById(deviceId),
    enabled: !!deviceId,
    refetchInterval: 30000, // Auto-refresh every 30 seconds
  });

  // Add debugging for the fetched device data
  useEffect(() => {
    if (device) {
      console.log("Device page - Full device data:", device);
      console.log("Device page - Connected devices value:", device.connectedDevices);
    }
  }, [device]);

  const handleRefresh = () => {
    setRefreshTrigger((prev) => prev + 1);
    refetch();
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <p className="text-lg font-medium">Loading device details...</p>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex items-center justify-center h-96">
        <p className="text-lg font-medium text-red-500">
          Error loading device: {(error as Error)?.message || "Unknown error"}
        </p>
      </div>
    );
  }

  if (!device) {
    return (
      <div className="flex items-center justify-center h-96">
        <p className="text-lg font-medium">Device not found</p>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <div className="flex items-center">
            <CircleIcon
              className={`h-3 w-3 mr-2 ${
                device.status === "online"
                  ? "text-green-500"
                  : "text-red-500"
              }`}
            />
            <h1 className="text-xl font-semibold">
              {device.manufacturer} {device.model}
            </h1>
          </div>
          <p className="text-sm text-gray-500">S/N: {device.serialNumber}</p>
        </div>
        
        <DeviceActions 
          device={device} 
          onRefresh={handleRefresh} 
        />
      </div>

      <Tabs defaultValue="overview" className="w-full">
        <TabsList className="mb-4">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="parameters">Parameters</TabsTrigger>
          <TabsTrigger value="configuration">Configuration</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          <DeviceStats device={device} />
          <DeviceInfo device={device} />
          <OpticalReadings device={device} />
          {device.connectedHosts && device.connectedHosts.length > 0 && (
            <ConnectedHosts hosts={device.connectedHosts} />
          )}
        </TabsContent>

        <TabsContent value="parameters">
          <DeviceParameters 
            device={device} 
          />
        </TabsContent>

        <TabsContent value="configuration">
          <Card className="p-6">
            <DeviceConfiguration device={device} />
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceDetailsPage;
