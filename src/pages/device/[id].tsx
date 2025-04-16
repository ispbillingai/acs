
import { useState, useEffect } from "react";
import { useParams } from "react-router-dom";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DeviceInfo } from "@/components/DeviceInfo";
import { DeviceParameters } from "@/components/DeviceParameters";
import { DeviceStats } from "@/components/DeviceStats";
import { DeviceActions } from "@/components/DeviceActions";
import { ConnectedClientsTable } from "@/components/ConnectedClientsTable";
import { Device, ConnectedClient, DeviceParameter } from "@/types";
import { config } from "@/config";

export default function DevicePage() {
  const { id } = useParams<{ id: string }>();
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [device, setDevice] = useState<Device | null>(null);
  const [parameters, setParameters] = useState<DeviceParameter[]>([]);
  const [clients, setClients] = useState<ConnectedClient[]>([]);

  // Fetch device details
  const fetchDevice = async () => {
    try {
      setLoading(true);
      setError(null);
      
      // Fetch device data
      const response = await fetch(`${config.API_BASE_URL}/devices.php?id=${id}`);
      if (!response.ok) {
        throw new Error(`Error fetching device data: ${response.statusText}`);
      }
      
      const data = await response.json();
      if (data.error) {
        throw new Error(data.error);
      }
      
      // Update the device state
      setDevice(data.device);
      
      // If parameters are returned, update parameters state
      if (data.parameters) {
        setParameters(data.parameters);
      }
      
      // If connected clients are returned, update clients state
      if (data.connectedClients) {
        setClients(data.connectedClients);
      }
      
      setLoading(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unknown error occurred');
      setLoading(false);
      console.error('Error fetching device:', err);
    }
  };

  // Initialize and set up polling
  useEffect(() => {
    if (!id) return;
    
    // Initial fetch
    fetchDevice();
    
    // Set up polling
    const intervalId = setInterval(() => {
      fetchDevice();
    }, config.ACS_SETTINGS.REFRESH_INTERVAL);
    
    // Clean up interval on component unmount
    return () => clearInterval(intervalId);
  }, [id]);

  if (loading && !device) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="flex items-center justify-center h-64">
            <p className="text-muted-foreground">Loading device data...</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (error) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="flex items-center justify-center h-64">
            <div className="text-center">
              <p className="text-error-dark font-medium mb-2">Error Loading Device</p>
              <p className="text-muted-foreground">{error}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!device) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="flex items-center justify-center h-64">
            <p className="text-muted-foreground">Device not found</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold tracking-tight">{device.model || 'Device'} Details</h1>
        <p className="text-muted-foreground">
          {device.manufacturer} {device.model} - {device.serialNumber}
        </p>
      </div>

      <div className="grid gap-6 md:grid-cols-7">
        <div className="space-y-6 md:col-span-5">
          <Tabs defaultValue="overview">
            <TabsList className="grid w-full grid-cols-3">
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="parameters">Parameters</TabsTrigger>
              <TabsTrigger value="clients">Connected Clients</TabsTrigger>
            </TabsList>
            
            <TabsContent value="overview" className="space-y-6 mt-6">
              <DeviceInfo device={device} />
              <DeviceStats device={device} />
            </TabsContent>
            
            <TabsContent value="parameters" className="space-y-6 mt-6">
              <DeviceParameters parameters={parameters} />
            </TabsContent>
            
            <TabsContent value="clients" className="space-y-6 mt-6">
              <ConnectedClientsTable clients={clients} />
            </TabsContent>
          </Tabs>
        </div>

        <div className="md:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Actions</CardTitle>
            </CardHeader>
            <CardContent>
              <DeviceActions device={device} onRefresh={fetchDevice} />
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
