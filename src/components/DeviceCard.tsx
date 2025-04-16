
import React from 'react';
import { Link } from 'react-router-dom';
import { 
  CircleIcon, 
  WifiIcon, 
  ServerIcon, 
  ClockIcon, 
  ArrowRightIcon,
  SmartphoneIcon,
  LaptopIcon,
  RouterIcon,
  ModemIcon,
  TvIcon,
  PrinterIcon,
  DevicesIcon
} from 'lucide-react';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Device } from '@/types';

interface DeviceCardProps {
  device: Device;
}

const getDeviceIcon = (model: string | undefined, manufacturer: string | undefined) => {
  const modelLower = model?.toLowerCase() || '';
  const manufacturerLower = manufacturer?.toLowerCase() || '';
  
  if (modelLower.includes('router') || manufacturerLower.includes('router')) {
    return <RouterIcon className="h-5 w-5 text-blue-500" />;
  } else if (modelLower.includes('modem') || manufacturerLower.includes('modem')) {
    return <ModemIcon className="h-5 w-5 text-blue-500" />;
  } else if (modelLower.includes('phone') || manufacturerLower.includes('phone')) {
    return <SmartphoneIcon className="h-5 w-5 text-blue-500" />;
  } else if (modelLower.includes('tv') || manufacturerLower.includes('tv')) {
    return <TvIcon className="h-5 w-5 text-blue-500" />;
  } else if (modelLower.includes('printer') || manufacturerLower.includes('printer')) {
    return <PrinterIcon className="h-5 w-5 text-blue-500" />;
  } else if (modelLower.includes('laptop') || manufacturerLower.includes('laptop')) {
    return <LaptopIcon className="h-5 w-5 text-blue-500" />;
  } else {
    return <DevicesIcon className="h-5 w-5 text-blue-500" />;
  }
};

export const DeviceCard = ({ device }: DeviceCardProps) => {
  return (
    <Card className="overflow-hidden bg-white border-blue-100 shadow-sm hover:shadow-md transition-all">
      <div className={`h-1 ${device.status === 'online' ? 'bg-green-500' : device.status === 'offline' ? 'bg-red-500' : 'bg-orange-500'}`}></div>
      
      <CardContent className="p-5">
        <div className="flex justify-between items-start">
          <div className="flex items-center space-x-2">
            {getDeviceIcon(device.model, device.manufacturer)}
            <div>
              <h3 className="font-semibold text-blue-900">
                {device.manufacturer || 'Unknown Manufacturer'}
              </h3>
              <p className="text-sm text-gray-600">{device.model || 'Unknown Model'}</p>
            </div>
          </div>
          
          <div className="flex items-center space-x-1 px-2 py-1 rounded-full bg-opacity-10 text-xs font-medium">
            <CircleIcon className={`h-2 w-2 ${
              device.status === 'online' ? 'text-green-600' : 
              device.status === 'offline' ? 'text-red-600' : 'text-orange-600'
            }`} />
            <span className={`${
              device.status === 'online' ? 'text-green-600' : 
              device.status === 'offline' ? 'text-red-600' : 'text-orange-600'
            } capitalize`}>
              {device.status}
            </span>
          </div>
        </div>
        
        <div className="mt-4 space-y-2">
          <div className="flex items-center text-sm text-gray-600">
            <ServerIcon className="h-4 w-4 mr-2 text-gray-400" />
            <span className="font-medium">S/N:</span>
            <span className="ml-2 truncate" title={device.serialNumber}>
              {device.serialNumber}
            </span>
          </div>
          
          <div className="flex items-center text-sm text-gray-600">
            <WifiIcon className="h-4 w-4 mr-2 text-gray-400" />
            <span className="font-medium">IP:</span>
            <span className="ml-2">{device.ipAddress}</span>
          </div>
          
          <div className="flex items-center text-sm text-gray-600">
            <ClockIcon className="h-4 w-4 mr-2 text-gray-400" />
            <span className="font-medium">Last Seen:</span>
            <span className="ml-2 truncate" title={device.lastContact}>
              {new Date(device.lastContact).toLocaleString()}
            </span>
          </div>
        </div>
      </CardContent>
      
      <CardFooter className="bg-gray-50 p-3 border-t border-blue-100">
        <Link 
          to={`/devices/${device.id}`}
          className="text-blue-600 hover:text-blue-800 text-sm font-medium inline-flex items-center w-full justify-end"
        >
          View Details
          <ArrowRightIcon className="w-4 h-4 ml-1" />
        </Link>
      </CardFooter>
    </Card>
  );
};
