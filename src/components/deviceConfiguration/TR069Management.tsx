
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { 
  AlertTriangle, 
  RefreshCw, 
  Terminal, 
  Server,
  CheckCircle, 
  XCircle, 
  PlayCircle,
  Wifi
} from "lucide-react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/components/ui/alert";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

interface TR069ManagementProps {
  deviceId: string;
  tr069SessionId: string | null;
}

const TR069Management: React.FC<TR069ManagementProps> = ({ 
  deviceId,
  tr069SessionId
}) => {
  const [selectedPort, setSelectedPort] = useState('30005');
  const [connectionTestStatus, setConnectionTestStatus] = useState<'idle' | 'testing' | 'success' | 'failure'>('idle');
  const [testResults, setTestResults] = useState<string[]>([]);
  const [parameterPath, setParameterPath] = useState('InternetGatewayDevice.LANDevice.1.WLANConfiguration.');
  const [discoveryInProgress, setDiscoveryInProgress] = useState(false);

  const testConnectionRequest = async () => {
    setConnectionTestStatus('testing');
    setTestResults([]);
    
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'test_connection_request');
      formData.append('port', selectedPort);
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }
      
      const result = await response.json();
      console.log('Connection test result:', result);
      
      if (result.success) {
        setConnectionTestStatus('success');
        setTestResults([
          `Testing connection request on port ${selectedPort}`,
          `URL: ${result.connection_request.url}`,
          `Command: ${result.connection_request.command}`
        ]);
        
        toast.success(`Connection request test prepared for port ${selectedPort}`);
      } else {
        setConnectionTestStatus('failure');
        setTestResults([`Error: ${result.message}`]);
        toast.error(result.message || 'Connection test failed');
      }
    } catch (error) {
      console.error('Error testing connection:', error);
      setConnectionTestStatus('failure');
      setTestResults([`Error: ${error instanceof Error ? error.message : 'Unknown error'}`]);
      toast.error('Connection test failed due to server error');
    }
  };

  const discoverParameters = async () => {
    setDiscoveryInProgress(true);
    
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'discover_parameters');
      formData.append('parameter_path', parameterPath);
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }
      
      const result = await response.json();
      console.log('Parameter discovery result:', result);
      
      if (result.success) {
        toast.success('Parameter discovery request generated');
        
        toast.info(
          <div className="space-y-2">
            <p className="font-medium">Parameter Discovery Request</p>
            <p className="text-sm">The request to discover parameters at path:</p>
            <code className="bg-slate-100 px-2 py-1 rounded block text-xs overflow-x-auto whitespace-pre-wrap">{result.parameter_path}</code>
            <p className="text-sm mt-2">This request will be sent during the next TR-069 session. Check the logs for results.</p>
          </div>,
          { duration: 10000 }
        );
      } else {
        toast.error(result.message || 'Parameter discovery failed');
      }
    } catch (error) {
      console.error('Error discovering parameters:', error);
      toast.error('Parameter discovery failed due to server error');
    } finally {
      setDiscoveryInProgress(false);
    }
  };

  return (
    <div>
      <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
        <Server className="h-5 w-5" />
        TR-069 Management
      </h3>
      
      <Alert className="mb-4">
        <AlertTriangle className="h-4 w-4" />
        <AlertTitle>TR-069 Troubleshooting</AlertTitle>
        <AlertDescription className="text-sm">
          This section helps troubleshoot TR-069 connections for Huawei HG8145V5 devices.
          Use these tools to test different connection ports and discover device parameters.
        </AlertDescription>
      </Alert>
      
      <div className="space-y-6">
        <div className="border rounded-md p-4 space-y-3">
          <h4 className="font-medium flex items-center gap-2">
            <Terminal className="h-4 w-4" /> 
            Connection Request Test
          </h4>
          <p className="text-sm text-gray-600">
            Test connection requests on different ports. Huawei ONTs typically use ports 30005 or 37215.
          </p>
          
          <div className="flex gap-3 flex-wrap">
            <Select value={selectedPort} onValueChange={setSelectedPort}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Select port" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="30005">Port 30005 (Huawei)</SelectItem>
                <SelectItem value="37215">Port 37215 (Huawei)</SelectItem>
                <SelectItem value="7547">Port 7547 (Standard)</SelectItem>
                <SelectItem value="4567">Port 4567 (Alternative)</SelectItem>
              </SelectContent>
            </Select>
            
            <Button 
              onClick={testConnectionRequest}
              disabled={connectionTestStatus === 'testing'}
              variant="outline"
              className="flex gap-2 items-center"
            >
              {connectionTestStatus === 'testing' ? (
                <>
                  <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full" />
                  Testing...
                </>
              ) : (
                <>
                  <PlayCircle className="h-4 w-4" />
                  Test Connection
                </>
              )}
            </Button>
          </div>
          
          {connectionTestStatus !== 'idle' && (
            <div className="mt-2 p-2 bg-slate-50 rounded-md border text-sm">
              <div className="flex items-center gap-2 mb-1">
                <span className="font-medium">Test Result:</span>
                {connectionTestStatus === 'success' && <CheckCircle className="h-4 w-4 text-green-500" />}
                {connectionTestStatus === 'failure' && <XCircle className="h-4 w-4 text-red-500" />}
                {connectionTestStatus === 'testing' && <RefreshCw className="h-4 w-4 animate-spin" />}
              </div>
              <div className="pl-2 space-y-1 text-xs">
                {testResults.map((result, index) => (
                  <div key={index} className="font-mono">{result}</div>
                ))}
              </div>
            </div>
          )}
        </div>
        
        <div className="border rounded-md p-4 space-y-3">
          <h4 className="font-medium flex items-center gap-2">
            <RefreshCw className="h-4 w-4" /> 
            Parameter Discovery
          </h4>
          <p className="text-sm text-gray-600">
            Discover TR-069 parameters on the device. Use this to verify the correct parameter paths.
          </p>
          
          <div className="space-y-3">
            <Input
              placeholder="Parameter Path"
              value={parameterPath}
              onChange={(e) => setParameterPath(e.target.value)}
            />
            
            <div className="space-x-2">
              <Button 
                onClick={discoverParameters}
                disabled={discoveryInProgress}
                variant="outline"
                className="flex gap-2 items-center"
              >
                {discoveryInProgress ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full" />
                    Discovering...
                  </>
                ) : (
                  <>
                    <RefreshCw className="h-4 w-4" />
                    Discover Parameters
                  </>
                )}
              </Button>
              
              <Button 
                variant="outline" 
                onClick={() => setParameterPath('InternetGatewayDevice.LANDevice.1.WLANConfiguration.')}
                title="Reset to WiFi parameters"
                size="icon"
              >
                <Wifi className="h-4 w-4" />
              </Button>
            </div>
            
            <div className="text-xs space-y-1">
              <p className="font-medium">Common parameter paths:</p>
              <ul className="list-disc pl-5 space-y-1">
                <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.</code></li>
                <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.ManagementServer.</code></li>
                <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.DeviceInfo.</code></li>
              </ul>
            </div>
          </div>
        </div>
        
        {tr069SessionId && (
          <div className="border rounded-md p-4 space-y-2">
            <h4 className="font-medium flex items-center gap-2">
              <Server className="h-4 w-4" /> 
              TR-069 Session Information
            </h4>
            <div className="text-sm">
              <div><span className="font-medium">Session ID:</span> {tr069SessionId}</div>
              <div><span className="font-medium">Status:</span> Pending connection request</div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default TR069Management;
