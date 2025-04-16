
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { DeviceInfo } from '@/components/DeviceInfo';
import { ConnectedClientsTable } from '@/components/ConnectedClientsTable';
import { DeviceParameters } from '@/components/DeviceParameters';
import { InfoIcon } from 'lucide-react';
import { Device } from '@/types';
import { DeviceActions } from '@/components/DeviceActions';
import { DeviceStats } from '@/components/DeviceStats';
import { DebugLogger } from '@/components/DebugLogger';

const DeviceDetailPage = () => {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showDebug, setShowDebug] = useState(false);

  useEffect(() => {
    const fetchDevice = async () => {
      setLoading(true);
      try {
        // In a real application, this would be an API call
        setTimeout(() => {
          // Mock device data
          const mockDevice: Device = {
            id: id || '1',
            serialNumber: '48575443F2D61173',
            manufacturer: 'Huawei Technologies Co., Ltd',
            model: 'HG8546M',
            softwareVersion: 'V5R019C10S125',
            hardwareVersion: '10C7.A',
            status: 'online',
            lastContact: new Date().toISOString(),
            ipAddress: '192.168.1.138',
            ssid: 'TR069',
            uptime: '39138', // This will be formatted to human-readable
            connectedClients: 4
          };
          
          console.log("Device data loaded:", mockDevice);
          setDevice(mockDevice);
          setLoading(false);
        }, 1500);
      } catch (err) {
        console.error("Error fetching device:", err);
        setError("Failed to load device information. Please try again later.");
        setLoading(false);
      }
    };

    if (id) {
      fetchDevice();
    } else {
      setError("No device ID provided");
      setLoading(false);
    }
  }, [id]);

  // Format uptime from seconds to days, hours, minutes
  const formatUptime = (uptimeSeconds: string | undefined): string => {
    if (!uptimeSeconds) return 'N/A';
    
    const seconds = parseInt(uptimeSeconds, 10);
    if (isNaN(seconds)) return 'N/A';
    
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;
    
    if (days > 0) {
      return `${days}d ${hours}h ${minutes}m`;
    } else if (hours > 0) {
      return `${hours}h ${minutes}m ${remainingSeconds}s`;
    } else if (minutes > 0) {
      return `${minutes}m ${remainingSeconds}s`;
    } else {
      return `${remainingSeconds}s`;
    }
  };

  if (loading) {
    return <div className="flex items-center justify-center min-h-screen">Loading device information...</div>;
  }

  if (error) {
    return (
      <Alert variant="destructive" className="max-w-2xl mx-auto mt-8">
        <InfoIcon className="h-4 w-4" />
        <AlertTitle>Error</AlertTitle>
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  if (!device) {
    return <div>No device found</div>;
  }

  // Format the uptime before displaying
  const formattedDevice = {
    ...device,
    uptime: formatUptime(device.uptime)
  };

  return (
    <div className="container py-6">
      <div className="mb-6 flex justify-between items-center">
        <h1 className="text-3xl font-bold">{device.model || 'Device'} Details</h1>
        <DeviceActions device={device} />
      </div>

      <DeviceStats device={device} />

      <div className="mt-6">
        <div className="flex justify-between mb-4">
          <h2 className="text-2xl font-semibold">Device Information</h2>
          <button 
            onClick={() => setShowDebug(!showDebug)}
            className="text-sm bg-blue-50 hover:bg-blue-100 text-blue-800 px-3 py-1.5 rounded border border-blue-200"
          >
            {showDebug ? "Hide Debug" : "Show Debug"}
          </button>
        </div>

        {showDebug && <DebugLogger data={device} title="Raw Device Data" className="mb-6" />}

        <DeviceInfo device={formattedDevice} />
      </div>

      <Tabs defaultValue="clients" className="mt-6">
        <TabsList>
          <TabsTrigger value="clients">Connected Clients</TabsTrigger>
          <TabsTrigger value="parameters">TR-069 Parameters</TabsTrigger>
        </TabsList>
        <TabsContent value="clients" className="py-4">
          <ConnectedClientsTable deviceId={device.id} />
        </TabsContent>
        <TabsContent value="parameters" className="py-4">
          <DeviceParameters deviceId={device.id} />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceDetailPage;
