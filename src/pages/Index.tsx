
import React, { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { DeviceStats } from '../components/DeviceStats';
import { DeviceCard } from '../components/DeviceCard';
import { HardDrive, WifiIcon, ServerIcon, Search, RefreshCw, Activity, Clock } from 'lucide-react';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { toast } from 'sonner';
import { Device } from '@/types';

const Index = () => {
  const {
    data: devices = [],
    isLoading,
    error,
    refetch
  } = useQuery({
    queryKey: ['devices'],
    queryFn: async () => {
      try {
        const response = await fetch('/backend/api/devices.php');
        if (!response.ok) {
          throw new Error('Failed to fetch devices');
        }
        return response.json();
      } catch (error) {
        console.error('Error fetching devices:', error);
        throw error;
      }
    },
  });

  // Automatically refetch data every 30 seconds
  useEffect(() => {
    const interval = setInterval(() => {
      refetch();
    }, 30000);
    
    return () => clearInterval(interval);
  }, [refetch]);

  useEffect(() => {
    if (error) {
      toast.error('Failed to load devices');
    }
  }, [error]);

  // Count statistics
  const onlineDevices = devices.filter((device: Device) => device.status === 'online').length;
  const offlineDevices = devices.filter((device: Device) => device.status === 'offline').length;
  const provisioningDevices = devices.filter((device: Device) => device.status === 'provisioning').length;
  
  return (
    <div className="space-y-6">
      {/* Header with search */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight text-blue-800">
            ACS Dashboard
          </h1>
          <p className="text-gray-600">
            Monitor and manage your TR-069 devices
          </p>
        </div>
        <div className="flex items-center gap-2">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-gray-500" />
            <Input
              type="search"
              placeholder="Search devices..."
              className="w-full pl-9 bg-white border-blue-100 focus-visible:ring-blue-400"
            />
          </div>
          <Button
            variant="outline"
            size="icon"
            onClick={() => refetch()}
            className="bg-white border-blue-100 text-blue-600 hover:bg-blue-50"
          >
            <RefreshCw className="h-4 w-4" />
            <span className="sr-only">Refresh</span>
          </Button>
        </div>
      </div>
      
      {isLoading ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {[...Array(3)].map((_, i) => (
            <Card key={i} className="bg-white border-blue-100 shadow-sm animate-pulse">
              <CardHeader className="pb-2">
                <div className="h-6 bg-blue-100 rounded w-24"></div>
              </CardHeader>
              <CardContent>
                <div className="h-12 bg-blue-100 rounded w-16 mb-2"></div>
                <div className="h-4 bg-blue-100 rounded w-32"></div>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <Card className="bg-white border-blue-100 shadow-sm hover:shadow-md transition-all">
              <CardHeader className="pb-2">
                <CardTitle className="text-lg text-blue-800 flex items-center gap-2">
                  <HardDrive className="h-5 w-5 text-blue-600" />
                  Total Devices
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-blue-900">{devices.length}</div>
                <p className="text-gray-600 text-sm">Devices registered in system</p>
              </CardContent>
            </Card>
            
            <Card className="bg-white border-green-100 shadow-sm hover:shadow-md transition-all">
              <CardHeader className="pb-2">
                <CardTitle className="text-lg text-green-800 flex items-center gap-2">
                  <Activity className="h-5 w-5 text-green-600" />
                  Online
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-green-600">{onlineDevices}</div>
                <p className="text-gray-600 text-sm">Devices currently online</p>
              </CardContent>
            </Card>
            
            <Card className="bg-white border-red-100 shadow-sm hover:shadow-md transition-all">
              <CardHeader className="pb-2">
                <CardTitle className="text-lg text-red-800 flex items-center gap-2">
                  <WifiIcon className="h-5 w-5 text-red-600" />
                  Offline
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-red-600">{offlineDevices}</div>
                <p className="text-gray-600 text-sm">Devices currently offline</p>
              </CardContent>
            </Card>
            
            <Card className="bg-white border-orange-100 shadow-sm hover:shadow-md transition-all">
              <CardHeader className="pb-2">
                <CardTitle className="text-lg text-orange-800 flex items-center gap-2">
                  <Clock className="h-5 w-5 text-orange-600" />
                  Provisioning
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-orange-600">{provisioningDevices}</div>
                <p className="text-gray-600 text-sm">Devices being provisioned</p>
              </CardContent>
            </Card>
          </div>
          
          <DeviceStats devices={devices} />
          
          <div className="mt-8">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold text-blue-800 flex items-center gap-2">
                <ServerIcon className="h-5 w-5" />
                Managed Devices
              </h2>
              
              <Link to="/devices">
                <Button variant="outline" className="text-blue-600 border-blue-200 hover:bg-blue-50">
                  View All Devices
                </Button>
              </Link>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mt-2">
              {devices.slice(0, 6).map((device: Device) => (
                <DeviceCard key={device.id} device={device} />
              ))}
            </div>
            
            {devices.length > 6 && (
              <div className="flex justify-center mt-4">
                <Link to="/devices">
                  <Button variant="outline" className="text-blue-600 border-blue-200 hover:bg-blue-50">
                    View All {devices.length} Devices
                  </Button>
                </Link>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
};

export default Index;
