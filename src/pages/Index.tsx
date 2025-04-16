import { useEffect, useState } from 'react';
import { Card } from "@/components/ui/card";
import { Device } from "@/types";
import { MoreVertical, Edit, Trash, Plus } from "lucide-react";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"
import { Button } from "@/components/ui/button";
import { DeviceStats } from "@/components/DeviceStats";
import { toast } from "@/components/ui/use-toast"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { ScrollArea, ScrollBar } from "@/components/ui/scroll-area"

const IndexPage = () => {
  const [devices, setDevices] = useState<Device[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('asc');
  const [sortBy, setSortBy] = useState<keyof Device>('serialNumber');
  const [searchQuery, setSearchQuery] = useState<string>('');

  useEffect(() => {
    fetchDevices();
  }, []);

  const fetchDevices = async () => {
    setIsLoading(true);
    try {
      const response = await fetch('/backend/api/get_devices.php');
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();
      setDevices(data);
      setError(null);
    } catch (e: any) {
      console.error("Could not fetch devices:", e);
      setError(e.message);
    } finally {
      setIsLoading(false);
    }
  };

  const getStatusBadgeColor = (status: string) => {
    switch (status) {
      case 'online':
        return 'bg-green-100 text-green-800 border-green-200';
      case 'offline':
        return 'bg-red-100 text-red-800 border-red-200';
      case 'warning':
        return 'bg-yellow-100 text-yellow-800 border-yellow-200';
      case 'provisioning':
        return 'bg-blue-100 text-blue-800 border-blue-200';
      default:
        return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  const handleStatusFilterChange = (event: React.ChangeEvent<HTMLSelectElement>) => {
    setStatusFilter(event.target.value);
  };

  const handleSortChange = (newSortBy: keyof Device) => {
    if (sortBy === newSortBy) {
      // Toggle sort order if the same column is clicked again
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
    } else {
      // Set new sort column and reset sort order to ascending
      setSortBy(newSortBy);
      setSortOrder('asc');
    }
  };

  const handleSearchChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    setSearchQuery(event.target.value);
  };

  const deleteDevice = async (deviceId: string) => {
    if (window.confirm("Are you sure you want to delete this device?")) {
      try {
        const response = await fetch(`/backend/api/delete_device.php?id=${deviceId}`, {
          method: 'DELETE',
        });

        if (!response.ok) {
          throw new Error(`Failed to delete device: ${response.status}`);
        }

        toast({
          title: "Device Deleted",
          description: "The device has been successfully deleted.",
        })

        // Refresh the device list
        fetchDevices();
      } catch (error: any) {
        console.error("Error deleting device:", error);
        toast({
          variant: "destructive",
          title: "Error",
          description: "Failed to delete the device.",
        })
      }
    }
  };

  const filteredDevices = devices.filter(device => {
    if (statusFilter === 'all') return true;
    return device.status === statusFilter;
  });

  const searchedDevices = filteredDevices.filter(device => {
    const searchTerms = searchQuery.toLowerCase().split(' ');
    const deviceString = Object.values(device).filter(Boolean).join(' ').toLowerCase();

    return searchTerms.every(term => deviceString.includes(term));
  });

  const sortedDevices = [...searchedDevices].sort((a, b) => {
    const aValue = a[sortBy] || '';
    const bValue = b[sortBy] || '';

    if (typeof aValue === 'number' && typeof bValue === 'number') {
      return sortOrder === 'asc' ? aValue - bValue : bValue - aValue;
    } else {
      const stringA = String(aValue).toLowerCase();
      const stringB = String(bValue).toLowerCase();

      if (stringA < stringB) return sortOrder === 'asc' ? -1 : 1;
      if (stringA > stringB) return sortOrder === 'asc' ? 1 : -1;
      return 0;
    }
  });

  const onlineDevices = devices.filter(device => device.status === 'online');
  const offlineDevices = devices.filter(device => device.status === 'offline');
  const warningDevices = devices.filter(device => device.status === 'warning');
  const provisioningDevices = devices.filter(device => device.status === 'provisioning');
  const activeDevices = devices.filter(device => device.status === 'online' || device.status === 'warning');

  return (
    <div className="container mx-auto p-6">
      <div className="mb-6 flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Device Management</h1>
          <p className="text-gray-500">Manage and monitor your connected devices.</p>
        </div>

        <Button>
          <Plus className="mr-2 h-4 w-4" /> Add Device
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <Card className="p-4 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold">Online Devices</h3>
            <p className="text-2xl font-bold text-green-600">{onlineDevices.length}</p>
          </div>
          <div className="text-green-600">
            {/* Icon or additional info */}
          </div>
        </Card>

        <Card className="p-4 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold">Offline Devices</h3>
            <p className="text-2xl font-bold text-red-600">{offlineDevices.length}</p>
          </div>
          <div className="text-red-600">
            {/* Icon or additional info */}
          </div>
        </Card>

        <Card className="p-4 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold">Devices in Warning</h3>
            <p className="text-2xl font-bold text-yellow-600">{warningDevices.length}</p>
          </div>
          <div className="text-yellow-600">
            {/* Icon or additional info */}
          </div>
        </Card>

        <Card className="p-4 flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold">Devices Provisioning</h3>
            <p className="text-2xl font-bold text-blue-600">{provisioningDevices.length}</p>
          </div>
          <div className="text-blue-600">
            {/* Icon or additional info */}
          </div>
        </Card>
      </div>

      {activeDevices.length > 0 && (
        <div className="mb-8">
          <h2 className="text-xl font-bold mb-4">Active Device Statistics</h2>
          <DeviceStats device={activeDevices[0]} />
        </div>
      )}

      <div className="flex flex-col md:flex-row justify-between items-center mb-4">
        <div className="flex items-center mb-2 md:mb-0">
          <Label htmlFor="statusFilter" className="mr-2 text-sm font-medium">Filter by Status:</Label>
          <select
            id="statusFilter"
            className="border rounded px-3 py-2 text-sm"
            value={statusFilter}
            onChange={handleStatusFilterChange}
          >
            <option value="all">All</option>
            <option value="online">Online</option>
            <option value="offline">Offline</option>
            <option value="warning">Warning</option>
            <option value="provisioning">Provisioning</option>
          </select>
        </div>

        <div className="flex items-center">
          <Label htmlFor="search" className="mr-2 text-sm font-medium">Search:</Label>
          <Input
            type="search"
            id="search"
            placeholder="Search devices..."
            className="border rounded px-3 py-2 text-sm"
            value={searchQuery}
            onChange={handleSearchChange}
          />
        </div>
      </div>

      {isLoading ? (
        <div className="text-center p-8">Loading devices...</div>
      ) : error ? (
        <div className="text-red-500 text-center p-8">Error: {error}</div>
      ) : (
        <ScrollArea>
          <Table>
            <TableCaption>A list of your devices.</TableCaption>
            <TableHeader>
              <TableRow>
                <TableHead className="w-[100px]">
                  <Button variant="link" onClick={() => handleSortChange('serialNumber')}>
                    Serial Number
                    {sortBy === 'serialNumber' && (sortOrder === 'asc' ? ' ▲' : ' ▼')}
                  </Button>
                </TableHead>
                <TableHead>
                  <Button variant="link" onClick={() => handleSortChange('manufacturer')}>
                    Manufacturer
                    {sortBy === 'manufacturer' && (sortOrder === 'asc' ? ' ▲' : ' ▼')}
                  </Button>
                </TableHead>
                <TableHead>
                  <Button variant="link" onClick={() => handleSortChange('model')}>
                    Model
                    {sortBy === 'model' && (sortOrder === 'asc' ? ' ▲' : ' ▼')}
                  </Button>
                </TableHead>
                <TableHead>
                  <Button variant="link" onClick={() => handleSortChange('ipAddress')}>
                    IP Address
                    {sortBy === 'ipAddress' && (sortOrder === 'asc' ? ' ▲' : ' ▼')}
                  </Button>
                </TableHead>
                <TableHead>
                  <Button variant="link" onClick={() => handleSortChange('status')}>
                    Status
                    {sortBy === 'status' && (sortOrder === 'asc' ? ' ▲' : ' ▼')}
                  </Button>
                </TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sortedDevices.map((device) => (
                <TableRow key={device.id}>
                  <TableCell className="font-medium">{device.serialNumber}</TableCell>
                  <TableCell>{device.manufacturer || 'N/A'}</TableCell>
                  <TableCell>{device.model || 'N/A'}</TableCell>
                  <TableCell>{device.ipAddress || 'N/A'}</TableCell>
                  <TableCell>
                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 ${getStatusBadgeColor(device.status)}`}>
                      {device.status}
                    </span>
                  </TableCell>
                  <TableCell className="text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" className="h-8 w-8 p-0">
                          <span className="sr-only">Open menu</span>
                          <MoreVertical className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                        <DropdownMenuItem onClick={() => { window.location.href = `/device/${device.id}` }}>
                          <Edit className="mr-2 h-4 w-4" />
                          View Details
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <AlertDialog>
                          <AlertDialogTrigger asChild>
                            <DropdownMenuItem className="text-red-500 focus:bg-red-50">
                              <Trash className="mr-2 h-4 w-4" />
                              <span>Delete</span>
                            </DropdownMenuItem>
                          </AlertDialogTrigger>
                          <AlertDialogContent>
                            <AlertDialogHeader>
                              <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
                              <AlertDialogDescription>
                                This action cannot be undone. This will permanently delete the device
                                and all of its data.
                              </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                              <AlertDialogCancel>Cancel</AlertDialogCancel>
                              <AlertDialogAction onClick={() => deleteDevice(device.id)}>Continue</AlertDialogAction>
                            </AlertDialogFooter>
                          </AlertDialogContent>
                        </AlertDialog>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <ScrollBar orientation="horizontal" />
        </ScrollArea>
      )}
    </div>
  );
};

export default IndexPage;
