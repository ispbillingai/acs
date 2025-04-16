
import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import DeviceInfo from '@/components/DeviceInfo';
import DeviceParameters from '@/components/DeviceParameters';
import DeviceActions from '@/components/DeviceActions';
import DeviceStats from '@/components/DeviceStats';
import ConnectedHosts from '@/components/ConnectedHosts';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/hooks/use-toast';

interface Device {
  id: string;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: 'online' | 'offline';
  ipAddress: string;
  lastContact: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  uptime?: string;
  connectedClients: number;
  ssid?: string;
  parameters?: Array<{
    name: string;
    value: string;
    type: string;
  }>;
  connectedHosts?: Array<{
    ipAddress: string;
    hostname: string;
    macAddress: string;
    isActive: boolean;
  }>;
}

const DeviceDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { toast } = useToast();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);

  const fetchDeviceDetails = async () => {
    try {
      setLoading(true);
      const response = await fetch(`/backend/api/devices.php?id=${id}`);
      if (!response.ok) {
        throw new Error('Failed to fetch device details');
      }
      const data = await response.json();
      setDevice(data);
    } catch (error) {
      console.error('Error fetching device details:', error);
      toast({
        title: 'Error',
        description: 'Failed to load device details',
        variant: 'destructive',
      });
    } finally {
      setLoading(false);
    }
  };

  const handleRefresh = async () => {
    setRefreshing(true);
    await fetchDeviceDetails();
    toast({
      title: 'Refreshed',
      description: 'Device information has been updated',
    });
    setRefreshing(false);
  };

  useEffect(() => {
    fetchDeviceDetails();
  }, [id]);

  if (loading && !device) {
    return (
      <div className="container mx-auto py-6 space-y-4">
        <div className="flex items-center space-x-2">
          <Button variant="outline" size="sm" onClick={() => navigate('/')}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to Dashboard
          </Button>
        </div>
        <Card>
          <CardHeader>
            <CardTitle>Loading device details...</CardTitle>
          </CardHeader>
        </Card>
      </div>
    );
  }

  if (!device) {
    return (
      <div className="container mx-auto py-6 space-y-4">
        <div className="flex items-center space-x-2">
          <Button variant="outline" size="sm" onClick={() => navigate('/')}>
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to Dashboard
          </Button>
        </div>
        <Card>
          <CardHeader>
            <CardTitle>Device not found</CardTitle>
            <CardDescription>The requested device could not be found.</CardDescription>
          </CardHeader>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto py-6 space-y-4">
      <div className="flex items-center justify-between">
        <Button variant="outline" size="sm" onClick={() => navigate('/')}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Back to Dashboard
        </Button>
        <Button variant="outline" size="sm" onClick={handleRefresh} disabled={refreshing}>
          <RefreshCw className={`mr-2 h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
          {refreshing ? 'Refreshing...' : 'Refresh'}
        </Button>
      </div>

      <Card>
        <CardHeader className="pb-2">
          <div className="flex justify-between items-start">
            <div>
              <CardTitle className="text-2xl">{device.model || 'Unknown Device'}</CardTitle>
              <CardDescription>{device.serialNumber}</CardDescription>
            </div>
            <Badge variant={device.status === 'online' ? 'default' : 'destructive'}>
              {device.status === 'online' ? 'Online' : 'Offline'}
            </Badge>
          </div>
        </CardHeader>
      </Card>

      <div className="grid gap-4 md:grid-cols-3">
        <div className="col-span-2 space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Device Information</CardTitle>
            </CardHeader>
            <CardContent>
              <DeviceInfo device={device} />
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Actions</CardTitle>
            </CardHeader>
            <CardContent>
              <DeviceActions device={device} />
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Connected Hosts</CardTitle>
            </CardHeader>
            <CardContent>
              <ConnectedHosts hosts={device.connectedHosts || []} deviceId={device.id} />
            </CardContent>
          </Card>
        </div>

        <div className="space-y-4">
          <DeviceStats
            deviceId={device.id}
            status={device.status}
            lastContact={device.lastContact}
            uptime={device.uptime}
            connectedClients={device.connectedClients}
          />

          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Device Parameters</CardTitle>
            </CardHeader>
            <CardContent>
              <DeviceParameters deviceId={device.id} />
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
};

export default DeviceDetailPage;
