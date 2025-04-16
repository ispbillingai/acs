
import React, { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbSeparator } from "@/components/ui/breadcrumb";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "sonner";
import { Home, RefreshCcw, SettingsIcon } from "lucide-react";
import { DeviceInfo } from "@/components/DeviceInfo";
import { ConnectedClientsTable } from "@/components/ConnectedClientsTable"; 
import { Device, DeviceParameter, ConnectedClient } from "@/types";

// Fetch device data from the API
const fetchDevice = async (id: string): Promise<Device> => {
  const response = await fetch(`/api/devices/${id}`);
  if (!response.ok) {
    throw new Error("Failed to fetch device");
  }
  return response.json();
};

// Fetch device parameters from the API
const fetchParameters = async (id: string): Promise<DeviceParameter[]> => {
  const response = await fetch(`/api/devices/${id}/parameters`);
  if (!response.ok) {
    throw new Error("Failed to fetch parameters");
  }
  return response.json();
};

// Fetch connected clients from the API
const fetchConnectedClients = async (id: string): Promise<ConnectedClient[]> => {
  const response = await fetch(`/api/devices/${id}/clients`);
  if (!response.ok) {
    throw new Error("Failed to fetch connected clients");
  }
  return response.json();
};

const DeviceDetailPage = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState("info");

  // Use React Query to fetch and cache the device data
  const { 
    data: device, 
    isLoading: isDeviceLoading, 
    isError: isDeviceError, 
    refetch: refetchDevice 
  } = useQuery({
    queryKey: ["device", id],
    queryFn: () => fetchDevice(id || ""),
    enabled: !!id,
  });

  // Use React Query to fetch and cache the parameters data
  const { 
    data: parameters, 
    isLoading: isParametersLoading, 
    isError: isParametersError, 
    refetch: refetchParameters 
  } = useQuery({
    queryKey: ["parameters", id],
    queryFn: () => fetchParameters(id || ""),
    enabled: !!id,
  });

  // Use React Query to fetch and cache the connected clients data
  const { 
    data: connectedClients, 
    isLoading: isClientsLoading, 
    isError: isClientsError, 
    refetch: refetchClients 
  } = useQuery({
    queryKey: ["connectedClients", id],
    queryFn: () => fetchConnectedClients(id || ""),
    enabled: !!id,
  });

  // Create a unified refetch function
  const handleRefresh = async () => {
    toast.promise(
      Promise.all([refetchDevice(), refetchParameters(), refetchClients()]),
      {
        loading: "Refreshing device data...",
        success: "Device data refreshed successfully",
        error: "Failed to refresh device data",
      }
    );
  };

  // If there's an error with any of the data fetching, show an error message
  useEffect(() => {
    if (isDeviceError || isParametersError || isClientsError) {
      toast.error("Failed to load device data");
    }
  }, [isDeviceError, isParametersError, isClientsError]);

  // If the page is still loading, show a loading message
  if (isDeviceLoading || isParametersLoading || isClientsLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-lg">Loading device data...</p>
      </div>
    );
  }

  // If the device data is not available, show an error message
  if (!device) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-4">
        <p className="text-lg">Device not found or failed to load</p>
        <Button onClick={() => navigate(-1)}>Go Back</Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Breadcrumb navigation */}
      <Breadcrumb>
        <BreadcrumbList>
          <BreadcrumbItem>
            <BreadcrumbLink href="/">
              <Home className="h-4 w-4" />
              <span className="ml-2">Home</span>
            </BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink href="/devices">Devices</BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink>{device.serialNumber || "Detail"}</BreadcrumbLink>
          </BreadcrumbItem>
        </BreadcrumbList>
      </Breadcrumb>

      {/* Page header */}
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Device Details</h1>
          <p className="text-muted-foreground">
            View and manage device information and configuration
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleRefresh}>
            <RefreshCcw className="mr-2 h-4 w-4" />
            Refresh
          </Button>
          <Button>
            <SettingsIcon className="mr-2 h-4 w-4" />
            Configure
          </Button>
        </div>
      </div>

      {/* Device status card */}
      <Card className="bg-gradient-to-br from-blue-50 to-purple-50 border-blue-100">
        <CardHeader className="pb-2">
          <CardTitle>Device Status</CardTitle>
          <CardDescription>
            Current status and basic information
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div className={`rounded-lg border p-3 ${device.status === 'online' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}`}>
              <div className="text-sm font-medium text-muted-foreground">Status</div>
              <div className={`text-xl font-bold ${device.status === 'online' ? 'text-green-600' : 'text-red-600'}`}>
                {device.status === 'online' ? 'Online' : 'Offline'}
              </div>
            </div>
            <div className="rounded-lg border bg-blue-50 border-blue-200 p-3">
              <div className="text-sm font-medium text-muted-foreground">Model</div>
              <div className="text-xl font-bold text-blue-600">{device.model || 'N/A'}</div>
            </div>
            <div className="rounded-lg border bg-purple-50 border-purple-200 p-3">
              <div className="text-sm font-medium text-muted-foreground">Connected Clients</div>
              <div className="text-xl font-bold text-purple-600">{device.connectedClients || '0'}</div>
            </div>
            <div className="rounded-lg border bg-indigo-50 border-indigo-200 p-3">
              <div className="text-sm font-medium text-muted-foreground">Last Contact</div>
              <div className="text-xl font-bold text-indigo-600">
                {device.lastContact ? new Date(device.lastContact).toLocaleTimeString() : 'N/A'}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Main content tabs */}
      <Tabs defaultValue={activeTab} onValueChange={setActiveTab} className="space-y-4">
        <TabsList>
          <TabsTrigger value="info">Information</TabsTrigger>
          <TabsTrigger value="clients">Connected Clients</TabsTrigger>
          <TabsTrigger value="parameters">Parameters</TabsTrigger>
        </TabsList>

        <TabsContent value="info" className="space-y-4">
          <DeviceInfo device={device} />
        </TabsContent>

        <TabsContent value="clients" className="space-y-4">
          {connectedClients && connectedClients.length > 0 ? (
            <ConnectedClientsTable clients={connectedClients} />
          ) : (
            <Card>
              <CardHeader>
                <CardTitle>No Connected Clients</CardTitle>
                <CardDescription>This device has no connected clients at the moment</CardDescription>
              </CardHeader>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="parameters" className="space-y-4">
          {parameters && parameters.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle>Device Parameters</CardTitle>
                <CardDescription>Technical parameters retrieved from the device</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="grid gap-4">
                  {parameters.map((param) => (
                    <div key={param.id} className="grid grid-cols-1 items-center gap-4 rounded-lg border p-4 md:grid-cols-5">
                      <div className="col-span-1 md:col-span-2 font-medium">{param.name}</div>
                      <div className="col-span-1 md:col-span-2 font-mono text-sm">{param.value}</div>
                      <div className="text-xs text-muted-foreground">{param.type}</div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : (
            <Card>
              <CardHeader>
                <CardTitle>No Parameters</CardTitle>
                <CardDescription>No parameters available for this device</CardDescription>
              </CardHeader>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceDetailPage;
