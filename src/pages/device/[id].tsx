
import { useState, useEffect } from "react";
import { useParams } from "react-router-dom";
import { HardDriveIcon, ServerIcon, RefreshCwIcon } from "lucide-react";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceParameters } from "@/components/DeviceParameters";
import { ConnectedClientsTable } from "@/components/ConnectedClientsTable";
import { DeviceActions } from "@/components/DeviceActions";
import { DeviceStats } from "@/components/DeviceStats";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useToast } from "@/hooks/use-toast";
import { useQuery } from "@tanstack/react-query";

// Function to fetch device details from backend
const fetchDeviceDetails = async (id: string) => {
  try {
    console.log("Fetching device details for ID:", id);
    const response = await fetch(`/backend/api/devices.php?id=${id}`, {
      method: "GET",
      headers: {
        "Content-Type": "application/json",
      },
    });

    if (!response.ok) {
      throw new Error(`Failed to fetch device: ${response.status}`);
    }

    const data = await response.json();
    console.log("Device data fetched:", data);
    return data;
  } catch (error) {
    console.error("Error fetching device details:", error);
    throw error;
  }
};

export default function DeviceDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast } = useToast();
  
  // Default device state
  const [device, setDevice] = useState<any>({
    id: id,
    status: "unknown",
    manufacturer: "Loading...",
    model: "Loading...",
    serialNumber: "Loading...",
    softwareVersion: "",
    hardwareVersion: "",
    ipAddress: "",
    lastContact: "",
    connectedClients: 0,
    parameters: [],
  });

  // Fetch device data using react-query
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["device", id],
    queryFn: () => fetchDeviceDetails(id || ""),
    enabled: !!id,
    retry: 2,
    staleTime: 30000,
  });

  // Update device state when data is fetched
  useEffect(() => {
    if (data) {
      console.log("Setting device data:", data);
      
      // Extract connected clients count if available
      const connectedClientCount = data.connectedHosts 
        ? data.connectedHosts.filter((host: any) => host.isActive).length 
        : 0;
      
      setDevice({
        ...data,
        connectedClients: connectedClientCount,
      });
    }
  }, [data]);

  // Handle refetch button click
  const handleRefresh = () => {
    refetch();
    toast({
      title: "Refreshing device data",
      description: "Fetching the latest information from the server",
    });
  };

  // Show loading state
  if (isLoading) {
    return (
      <div className="w-full h-full flex items-center justify-center min-h-[400px]">
        <div className="flex flex-col items-center gap-4">
          <RefreshCwIcon className="h-12 w-12 text-blue-500 animate-spin" />
          <p className="text-lg font-medium text-blue-950">Loading device data...</p>
        </div>
      </div>
    );
  }

  // Show error state
  if (error) {
    return (
      <div className="w-full h-full flex items-center justify-center min-h-[400px] bg-red-50 rounded-lg p-6">
        <div className="flex flex-col items-center gap-4 max-w-md">
          <ServerIcon className="h-12 w-12 text-red-500" />
          <h2 className="text-xl font-bold text-red-700">Error Loading Device</h2>
          <p className="text-center text-red-600">
            {error instanceof Error ? error.message : "Failed to load device data"}
          </p>
          <Button onClick={handleRefresh} variant="outline">
            <RefreshCwIcon className="mr-2 h-4 w-4" />
            Try Again
          </Button>
        </div>
      </div>
    );
  }

  // Render device details with tabs for different sections
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <HardDriveIcon className="h-6 w-6 text-blue-600" />
            {device.manufacturer || "Huawei"} {device.model || "Device"}
          </h1>
          <p className="text-gray-500">Serial Number: {device.serialNumber}</p>
        </div>
        <Button onClick={handleRefresh} variant="outline" size="sm">
          <RefreshCwIcon className="mr-2 h-4 w-4" />
          Refresh
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="md:col-span-3">
          <DeviceStats device={device} />
        </div>
      </div>

      <Tabs defaultValue="info" className="w-full">
        <TabsList className="grid grid-cols-3 mb-6">
          <TabsTrigger value="info">Device Info</TabsTrigger>
          <TabsTrigger value="parameters">Parameters</TabsTrigger>
          <TabsTrigger value="clients">Connected Clients</TabsTrigger>
        </TabsList>
        
        <TabsContent value="info" className="mt-0">
          <DeviceInfo device={device} />
          <div className="mt-6">
            <DeviceActions deviceId={device.id} />
          </div>
        </TabsContent>
        
        <TabsContent value="parameters" className="mt-0">
          <DeviceParameters deviceId={device.id} />
        </TabsContent>
        
        <TabsContent value="clients" className="mt-0">
          <ConnectedClientsTable deviceId={device.id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
