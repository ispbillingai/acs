
import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DeviceInfo } from '@/components/DeviceInfo';
import { DeviceStats } from '@/components/DeviceStats';
import { DeviceActions } from '@/components/DeviceActions';
import { DeviceParameters } from '@/components/DeviceParameters';
import { ConnectedClientsTable } from '@/components/ConnectedClientsTable';
import { DebugLogger } from '@/components/DebugLogger';
import { Separator } from '@/components/ui/separator';
import { 
  Cpu, Network, MonitorSmartphone, Wifi, Server, Users, 
  Clock, ShieldAlert, Settings, AlertCircle
} from 'lucide-react';

interface Device {
  id: string;
  manufacturer: string;
  model: string;
  serialNumber: string;
  ipAddress: string;
  status: string;
  lastContact: string;
  softwareVersion: string;
  hardwareVersion: string;
  ssid: string;
  ssidPassword: string;
  uptime: string;
  connectedClients: number;
}

const DeviceDetailsPage = () => {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [debugInfo, setDebugInfo] = useState<any>({ logs: [] });

  useEffect(() => {
    const fetchDeviceDetails = async () => {
      setLoading(true);
      try {
        console.log('Fetching device details for ID:', id);
        const response = await fetch(`/backend/api/devices.php?id=${id}`);
        const data = await response.json();
        console.log('Device API response:', data);
        
        if (data.success) {
          setDevice(data.device);
          setDebugInfo(prev => ({
            ...prev,
            apiResponse: data,
            device: data.device
          }));
        } else {
          setError(data.message || 'Failed to fetch device details');
          setDebugInfo(prev => ({
            ...prev,
            error: data.message,
            apiResponse: data
          }));
        }
      } catch (err) {
        console.error('Error fetching device details:', err);
        setError('Failed to fetch device details. Please try again.');
        setDebugInfo(prev => ({
            ...prev,
            error: err instanceof Error ? err.message : String(err),
            stack: err instanceof Error ? err.stack : undefined
        }));
      } finally {
        setLoading(false);
      }
    };

    if (id) {
      fetchDeviceDetails();
    }
  }, [id]);

  // Additional logging to help debug manufacturer update issues
  useEffect(() => {
    if (device) {
      console.log('Device state updated:', {
        manufacturer: device.manufacturer,
        model: device.model,
        serialNumber: device.serialNumber
      });
      
      // Add to debug info
      setDebugInfo(prev => ({
        ...prev,
        logs: [...(prev.logs || []), {
          timestamp: new Date().toISOString(),
          event: 'device_state_updated',
          manufacturer: device.manufacturer,
          model: device.model,
          deviceId: id
        }]
      }));
    }
  }, [device, id]);

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <Card className="bg-white/60 backdrop-blur-md">
          <CardContent className="p-6">
            <div className="flex items-center justify-center h-64">
              <div className="flex flex-col items-center space-y-4">
                <div className="w-10 h-10 border-t-2 border-blue-500 rounded-full animate-spin"></div>
                <p className="text-lg text-blue-800 font-medium">Loading device details...</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (error) {
    return (
      <div className="container mx-auto p-6">
        <Card className="bg-red-50 border-red-200">
          <CardContent className="p-6">
            <div className="flex items-center space-x-2 text-red-600 mb-4">
              <AlertCircle className="h-6 w-6" />
              <h2 className="text-xl font-semibold">Error</h2>
            </div>
            <p className="text-gray-800">{error}</p>
            <DebugLogger data={debugInfo} title="Debug Information" />
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!device) {
    return (
      <div className="container mx-auto p-6">
        <Card className="bg-yellow-50 border-yellow-200">
          <CardContent className="p-6">
            <div className="flex items-center space-x-2 text-yellow-600 mb-4">
              <AlertCircle className="h-6 w-6" />
              <h2 className="text-xl font-semibold">Device Not Found</h2>
            </div>
            <p className="text-gray-800">The requested device could not be found or access is denied.</p>
            <DebugLogger data={debugInfo} title="Debug Information" />
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-4 lg:p-6">
      <div className="mb-6">
        <h1 className="text-2xl lg:text-3xl font-bold text-blue-900">
          Device Details
        </h1>
        <p className="text-gray-600">
          Managing device {device.manufacturer} {device.model} ({device.serialNumber})
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6">
        <Card className="overflow-hidden border-blue-100">
          <CardHeader className="bg-gradient-to-r from-blue-500 to-blue-600 text-white">
            <CardTitle className="flex items-center text-xl">
              <MonitorSmartphone className="h-6 w-6 mr-2" />
              Device Information
            </CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <DeviceInfo device={device} />
            
            {/* Add Debug Logger that's initially hidden */}
            <DebugLogger data={debugInfo} title="Device Debug Information" />
          </CardContent>
        </Card>

        <Tabs defaultValue="stats" className="w-full">
          <TabsList className="grid grid-cols-4 mb-6">
            <TabsTrigger value="stats" className="flex items-center">
              <Cpu className="h-4 w-4 mr-2" />
              <span className="hidden sm:inline">Statistics</span>
              <span className="sm:hidden">Stats</span>
            </TabsTrigger>
            <TabsTrigger value="clients" className="flex items-center">
              <Users className="h-4 w-4 mr-2" />
              <span className="hidden sm:inline">Connected Clients</span>
              <span className="sm:hidden">Clients</span>
            </TabsTrigger>
            <TabsTrigger value="params" className="flex items-center">
              <Server className="h-4 w-4 mr-2" />
              <span className="hidden sm:inline">Parameters</span>
              <span className="sm:hidden">Params</span>
            </TabsTrigger>
            <TabsTrigger value="actions" className="flex items-center">
              <Settings className="h-4 w-4 mr-2" />
              <span className="hidden sm:inline">Actions</span>
              <span className="sm:hidden">Actions</span>
            </TabsTrigger>
          </TabsList>

          <TabsContent value="stats">
            <Card>
              <CardHeader className="bg-gradient-to-r from-blue-50 to-blue-100">
                <CardTitle className="flex items-center text-blue-800">
                  <Network className="h-5 w-5 mr-2" />
                  Device Statistics
                </CardTitle>
              </CardHeader>
              <CardContent className="p-6">
                <DeviceStats devices={[device]} />
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="actions">
            <Card>
              <CardHeader className="bg-gradient-to-r from-blue-50 to-blue-100">
                <CardTitle className="flex items-center text-blue-800">
                  <Settings className="h-5 w-5 mr-2" />
                  Device Actions
                </CardTitle>
              </CardHeader>
              <CardContent className="p-6">
                <DeviceActions device={device} />
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="params">
            <Card>
              <CardHeader className="bg-gradient-to-r from-blue-50 to-blue-100">
                <CardTitle className="flex items-center text-blue-800">
                  <Server className="h-5 w-5 mr-2" />
                  Device Parameters
                </CardTitle>
              </CardHeader>
              <CardContent className="p-6">
                <DeviceParameters deviceId={id} />
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="clients">
            <Card>
              <CardHeader className="bg-gradient-to-r from-blue-50 to-blue-100">
                <CardTitle className="flex items-center text-blue-800">
                  <Users className="h-5 w-5 mr-2" />
                  Connected Clients
                </CardTitle>
              </CardHeader>
              <CardContent className="p-6">
                <ConnectedClientsTable device={device} />
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
};

export default DeviceDetailsPage;
