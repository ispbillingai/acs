
import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { DeviceInfo } from '@/components/DeviceInfo';
import { DeviceActions } from '@/components/DeviceActions';
import { DeviceParameters } from '@/components/DeviceParameters';
import { ConnectedClientsTable } from '@/components/ConnectedClientsTable';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { CircleIcon, WifiIcon, ServerIcon } from 'lucide-react';
import { toast } from 'sonner';
import { Device } from '@/types';

interface DeviceDetailProps {}

const DeviceDetail: React.FC<DeviceDetailProps> = () => {
  const { id } = useParams<{ id: string }>();

  const {
    data: device,
    isLoading,
    error,
    refetch
  } = useQuery({
    queryKey: ['device', id],
    queryFn: async () => {
      try {
        const response = await fetch(`/backend/api/devices.php?id=${id}`);
        if (!response.ok) {
          throw new Error('Failed to fetch device');
        }
        return response.json();
      } catch (err) {
        console.error('Error fetching device:', err);
        throw err;
      }
    },
  });

  useEffect(() => {
    if (error) {
      toast.error('Failed to load device data');
    }
  }, [error]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-pulse space-y-4">
          <div className="h-4 bg-blue-200 rounded w-32"></div>
          <div className="h-4 bg-blue-200 rounded w-64"></div>
          <div className="h-4 bg-blue-200 rounded w-48"></div>
        </div>
      </div>
    );
  }

  if (error || !device) {
    return (
      <div className="flex flex-col items-center justify-center min-h-screen space-y-4">
        <h2 className="text-2xl font-bold text-red-600">Error Loading Device</h2>
        <p className="text-gray-600">Could not load device information.</p>
        <button 
          onClick={() => refetch()}
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors"
        >
          Try Again
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">
            Device Details
          </h1>
          <p className="text-muted-foreground">
            Model: {device.model || 'Unknown'} | Serial: {device.serialNumber}
          </p>
        </div>
        <div className="flex items-center gap-2 bg-white px-4 py-2 rounded-md shadow-sm">
          <CircleIcon 
            className={`h-3 w-3 ${
              device.status === 'online' ? 'text-green-600' : 'text-red-600'
            }`} 
          />
          <span className="font-medium capitalize">
            {device.status}
          </span>
          <span className="text-gray-400">|</span>
          <WifiIcon className="h-4 w-4 text-blue-500" />
          <span className="text-sm text-gray-600">
            {device.ipAddress}
          </span>
        </div>
      </div>

      <Tabs defaultValue="overview" className="w-full">
        <TabsList className="grid w-full grid-cols-4 mb-4">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="parameters">Parameters</TabsTrigger>
          <TabsTrigger value="clients">Connected Clients</TabsTrigger>
          <TabsTrigger value="actions">Actions</TabsTrigger>
        </TabsList>
        
        <TabsContent value="overview" className="space-y-4">
          <DeviceInfo device={device} />
          
          <Card className="bg-gradient-to-br from-white to-blue-50 border border-blue-100 shadow-md">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ServerIcon className="h-5 w-5 text-blue-500" />
                Device Information
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {device.parameters && device.parameters.map((param: any, index: number) => (
                  <div key={index} className="space-y-1 bg-white p-3 rounded-lg border border-blue-50 shadow-sm hover:shadow transition-shadow">
                    <p className="text-sm text-blue-500 font-medium">{param.name}</p>
                    <p className="font-medium text-gray-800 truncate" title={param.value}>{param.value}</p>
                    <p className="text-xs text-gray-500">{param.type}</p>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>
        
        <TabsContent value="parameters" className="space-y-4">
          <DeviceParameters device={device} />
        </TabsContent>
        
        <TabsContent value="clients" className="space-y-4">
          <ConnectedClientsTable device={device} />
        </TabsContent>
        
        <TabsContent value="actions" className="space-y-4">
          <DeviceActions device={device} />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceDetail;
