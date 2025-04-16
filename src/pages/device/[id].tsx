import React from 'react';
import { DeviceParameters } from '@/components/DeviceParameters';
import { config } from '@/config/index';
import { Button } from '@/components/ui/button';

// Define the Device interface for TypeScript
interface Device {
  id: string;
  status: string;
  manufacturer: string;
  model: string;
  serialNumber: string;
  softwareVersion?: string;
  hardwareVersion?: string;
  ipAddress: string;
  lastContact: string;
  connectedClients: number;
  uptime?: string;
}

const DevicePage = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const fetchDevice = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(`${API_BASE_URL}/api/devices/${id}`);
      if (!response.ok) {
        throw new Error(`Failed to fetch device: ${response.statusText}`);
      }
      const data = await response.json();
      setDevice(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unknown error occurred');
      console.error('Error fetching device:', err);
    } finally {
      setLoading(false);
    }
  };

  const refreshDevice = async () => {
    setRefreshing(true);
    try {
      await fetchDevice();
    } finally {
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchDevice();
  }, [id]);

  // Helper function for formatting dates
  const formatDate = (dateString: string) => {
    if (!dateString) return 'Unknown';
    const date = new Date(dateString);
    return date.toLocaleString();
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <RefreshCw className="w-8 h-8 animate-spin mx-auto mb-4" />
          <p>Loading device information...</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="container mx-auto p-4">
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative" role="alert">
          <strong className="font-bold">Error:</strong>
          <span className="block sm:inline"> {error}</span>
          <Button 
            variant="outline" 
            className="mt-2" 
            onClick={() => navigate('/devices')}
          >
            <ArrowLeft className="mr-2 h-4 w-4" /> Back to Devices
          </Button>
        </div>
      </div>
    );
  }

  if (!device) {
    return (
      <div className="container mx-auto p-4">
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded relative" role="alert">
          <strong className="font-bold">Device Not Found:</strong>
          <span className="block sm:inline"> The requested device could not be found.</span>
          <Button 
            variant="outline" 
            className="mt-2" 
            onClick={() => navigate('/devices')}
          >
            <ArrowLeft className="mr-2 h-4 w-4" /> Back to Devices
          </Button>
        </div>
      </div>
    );
  }

  // Define the deviceSummary with properties that match the required type
  const deviceSummary = {
    status: device.status || 'unknown',
    manufacturer: device.manufacturer || 'Unknown',
    model: device.model || 'Unknown',
    serialNumber: device.serialNumber || 'Unknown',
    softwareVersion: device.softwareVersion || 'Unknown',
    hardwareVersion: device.hardwareVersion || 'Unknown',
    ipAddress: device.ipAddress || 'Unknown',
    lastContact: device.lastContact || 'Unknown',
    connectedClients: device.connectedClients || 0,
    uptime: device.uptime || 'Unknown'
  };

  return (
    <div className="container mx-auto p-4">
      <div className="flex justify-between items-center mb-4">
        <Button 
          variant="default" 
          onClick={() => navigate('/devices')}
        >
          <ArrowLeft className="mr-2 h-4 w-4" /> Back to Devices
        </Button>
        <Button 
          variant="outline" 
          onClick={refreshDevice}
          disabled={refreshing}
        >
          <RefreshCw className={`mr-2 h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} /> 
          {refreshing ? 'Refreshing...' : 'Refresh'}
        </Button>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <div className="flex justify-between items-center">
              <CardTitle>{device.model}</CardTitle>
              <Badge variant={device.status === 'online' ? 'success' : 'destructive'}>
                {device.status === 'online' ? 'Online' : 'Offline'}
              </Badge>
            </div>
            <CardDescription>
              Serial: {device.serialNumber}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4">
              <div className="grid grid-cols-2 gap-2">
                <div className="text-sm font-medium">Manufacturer</div>
                <div className="text-sm">{device.manufacturer}</div>
                
                <div className="text-sm font-medium">IP Address</div>
                <div className="text-sm">{device.ipAddress}</div>
                
                <div className="text-sm font-medium">Software Version</div>
                <div className="text-sm">{deviceSummary.softwareVersion}</div>
                
                <div className="text-sm font-medium">Hardware Version</div>
                <div className="text-sm">{deviceSummary.hardwareVersion}</div>
                
                <div className="text-sm font-medium">Connected Clients</div>
                <div className="text-sm">{device.connectedClients}</div>
                
                <div className="text-sm font-medium">Last Contact</div>
                <div className="text-sm">{formatDate(device.lastContact)}</div>
                
                <div className="text-sm font-medium">Uptime</div>
                <div className="text-sm">{deviceSummary.uptime}</div>
              </div>
            </div>
          </CardContent>
        </Card>

        <DeviceParameters deviceId={id || ''} />
      </div>
    </div>
  );
};

export default DevicePage;
