
import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import DeviceInfo from '../../components/DeviceInfo';
import DeviceActions from '../../components/DeviceActions';
import DeviceParameters from '../../components/DeviceParameters';
import ConnectedHosts from '../../components/ConnectedHosts';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../../components/ui/tabs';
import { Separator } from '../../components/ui/separator';
import { useToast } from '../../hooks/use-toast';
import { useQuery } from '@tanstack/react-query';

// Define Device interface with all required properties
interface Device {
  id: number;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: string;
  lastContact: string;
  ipAddress: string;
  softwareVersion: string;
  hardwareVersion: string;
  connectedClients: number;
  uptime: string;
  parameters?: Parameter[];
  connectedHosts?: Host[];
}

interface Parameter {
  name: string;
  value: string;
  type: string;
}

interface Host {
  id?: number;
  ipAddress: string;
  hostname: string;
  macAddress?: string;
  isActive?: boolean;
  lastSeen?: string;
}

const DevicePage = () => {
  const { id } = useParams<{ id: string }>();
  const { toast } = useToast();
  const [activeTab, setActiveTab] = useState('info');

  // Fetch device data
  const { data: device, isLoading, error } = useQuery({
    queryKey: ['device', id],
    queryFn: async () => {
      const response = await fetch(`/backend/api/devices.php?id=${id}`);
      if (!response.ok) {
        throw new Error('Failed to fetch device data');
      }
      return response.json() as Promise<Device>;
    }
  });

  // Show error toast if fetch fails
  useEffect(() => {
    if (error) {
      toast({
        title: 'Error',
        description: 'Failed to load device data',
        variant: 'destructive',
      });
    }
  }, [error, toast]);

  // Handle loading state
  if (isLoading) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <div className="bg-white rounded-lg shadow p-6 animate-pulse">
            <div className="h-6 bg-gray-200 rounded w-1/4 mb-4"></div>
            <div className="h-4 bg-gray-200 rounded w-2/3 mb-2"></div>
            <div className="h-4 bg-gray-200 rounded w-1/2"></div>
          </div>
        </div>
      </div>
    );
  }

  // Ensure device data exists
  if (!device) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <div className="bg-white rounded-lg shadow p-6 text-center">
            <h2 className="text-xl font-semibold">Device not found</h2>
            <p className="mt-2 text-gray-600">The device you're looking for doesn't exist or has been removed.</p>
            <a href="/" className="text-blue-600 hover:text-blue-800 mt-4 inline-block">← Back to Dashboard</a>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="max-w-7xl mx-auto space-y-8">
        <div className="flex items-center justify-between">
          <div>
            <a href="/" className="text-blue-600 hover:text-blue-800">← Back to Dashboard</a>
            <h1 className="text-3xl font-semibold tracking-tight mt-2">
              Device Details
            </h1>
          </div>
          <span className={`px-3 py-1 text-sm rounded-full ${device.status === 'online' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
            {device.status === 'online' ? 'Online' : 'Offline'}
          </span>
        </div>

        <Tabs defaultValue={activeTab} onValueChange={setActiveTab}>
          <TabsList className="mb-4">
            <TabsTrigger value="info">Device Info</TabsTrigger>
            <TabsTrigger value="parameters">Parameters</TabsTrigger>
            <TabsTrigger value="hosts">Connected Hosts</TabsTrigger>
          </TabsList>
          
          <TabsContent value="info">
            <Card>
              <CardHeader>
                <CardTitle>Device Information</CardTitle>
              </CardHeader>
              <CardContent>
                <DeviceInfo device={device} />
              </CardContent>
            </Card>
            
            <div className="mt-6">
              <DeviceActions device={device} />
            </div>
          </TabsContent>
          
          <TabsContent value="parameters">
            <Card>
              <CardHeader>
                <CardTitle>Device Parameters</CardTitle>
              </CardHeader>
              <CardContent>
                <DeviceParameters parameters={device.parameters || []} />
              </CardContent>
            </Card>
          </TabsContent>
          
          <TabsContent value="hosts">
            <Card>
              <CardHeader>
                <CardTitle>Connected Hosts</CardTitle>
              </CardHeader>
              <CardContent>
                <ConnectedHosts hosts={device.connectedHosts || []} />
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
};

export default DevicePage;
