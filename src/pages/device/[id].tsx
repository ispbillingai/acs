
import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DeviceParameters } from '@/components/DeviceParameters';
import { config } from '@/config/index';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

// Define the Device interface for TypeScript
interface Device {
  id: number;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: 'online' | 'offline' | 'provisioning';
  lastContact: string;
  ipAddress: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  parameters: Array<{
    name: string;
    value: string;
    type: string;
  }>;
  connectedHosts: Array<{
    id: number;
    ipAddress: string;
    macAddress: string;
    hostname: string;
    lastSeen: string;
    isActive: boolean;
  }>;
}

const DevicePage = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  
  const fetchDevice = async () => {
    try {
      setLoading(true);
      setError(null);
      console.log('Fetching device with ID:', id);
      
      const response = await fetch(`${config.API_BASE_URL}/devices.php?id=${id}`);
      console.log('Response status:', response.status);
      
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to fetch device');
      }
      
      const data = await response.json();
      console.log('Device data received:', data);
      setDevice(data);
    } catch (err) {
      console.error('Error fetching device:', err);
      setError(err instanceof Error ? err.message : 'Unknown error occurred');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };
  
  useEffect(() => {
    fetchDevice();
    // Set up polling for real-time updates
    const interval = setInterval(fetchDevice, config.ACS_SETTINGS.REFRESH_INTERVAL);
    return () => clearInterval(interval);
  }, [id]);
  
  const handleRefresh = () => {
    setRefreshing(true);
    fetchDevice();
  };
  
  if (loading && !device) {
    return (
      <div className="container mx-auto p-4">
        <Button 
          variant="outline" 
          onClick={() => navigate('/devices')}
        >
          <ArrowLeft className="mr-2 h-4 w-4" /> Back to Devices
        </Button>
        <div className="flex justify-center items-center h-[60vh]">
          <p className="text-lg">Loading device information...</p>
        </div>
      </div>
    );
  }
  
  if (error) {
    return (
      <div className="container mx-auto p-4">
        <Button 
          variant="outline" 
          onClick={() => navigate('/devices')}
        >
          <ArrowLeft className="mr-2 h-4 w-4" /> Back to Devices
        </Button>
        <div className="flex justify-center items-center h-[60vh] flex-col">
          <p className="text-lg text-red-500 mb-4">Error: {error}</p>
          <Button onClick={fetchDevice}>Try Again</Button>
        </div>
      </div>
    );
  }
  
  return (
    <div className="container mx-auto p-4">
      <div className="flex justify-between items-center mb-4">
        <Button 
          variant="default" 
          onClick={() => navigate('/devices')}
        >
          <ArrowLeft className="mr-2 h-4 w-4" /> Back to Devices
        </Button>
        
        <Button variant="outline" onClick={handleRefresh} disabled={refreshing}>
          <RefreshCw className="mr-2 h-4 w-4" size={16} className={refreshing ? 'animate-spin' : ''} /> 
          {refreshing ? 'Refreshing...' : 'Refresh'}
        </Button>
      </div>
      
      <Card className="mb-6">
        <CardHeader>
          <div className="flex justify-between items-center">
            <CardTitle>Device {device?.model}</CardTitle>
            <Badge variant={device?.status === 'online' ? 'success' : 'destructive'}>
              {device?.status}
            </Badge>
          </div>
          <CardDescription>
            Serial Number: {device?.serialNumber}
          </CardDescription>
          <CardDescription>
            Last Seen: {device?.lastContact}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <p><strong>Manufacturer:</strong> {device?.manufacturer}</p>
              <p><strong>Model:</strong> {device?.model}</p>
              <p><strong>IP Address:</strong> {device?.ipAddress}</p>
            </div>
            <div>
              <p><strong>Software Version:</strong> {device?.softwareVersion || 'N/A'}</p>
              <p><strong>Hardware Version:</strong> {device?.hardwareVersion || 'N/A'}</p>
              <p><strong>Connected Clients:</strong> {device?.connectedHosts?.filter(host => host.isActive).length || 0}</p>
            </div>
          </div>
        </CardContent>
      </Card>
      
      {device && <DeviceParameters device={device} />}
    </div>
  );
};

export default DevicePage;
