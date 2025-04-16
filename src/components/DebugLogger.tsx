
import { useState } from 'react';
import { AlertTriangleIcon, XIcon, EyeIcon, EyeOffIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

interface DebugLoggerProps {
  data: any;
  title?: string;
  className?: string; // Add className prop to support additional styling
}

export const DebugLogger = ({ data, title = "Debug Information", className = "" }: DebugLoggerProps) => {
  const [isVisible, setIsVisible] = useState(false);

  const toggleVisibility = () => {
    setIsVisible(!isVisible);
  };

  return (
    <Card className={`mt-6 bg-red-50 p-4 rounded-lg border border-red-100 ${className}`}>
      <div className="flex items-center justify-between text-red-700 mb-2">
        <div className="flex items-center">
          <AlertTriangleIcon className="h-5 w-5 mr-2" />
          <h3 className="font-semibold">{title}</h3>
        </div>
        <div className="flex gap-2">
          <Button 
            onClick={toggleVisibility} 
            variant="outline" 
            size="sm" 
            className="h-7 px-2 bg-white text-red-700 border-red-200 hover:bg-red-100"
          >
            {isVisible ? (
              <><EyeOffIcon className="h-4 w-4 mr-1" /> Hide</>
            ) : (
              <><EyeIcon className="h-4 w-4 mr-1" /> Show</>
            )}
          </Button>
        </div>
      </div>

      {isVisible && (
        <div className="bg-white p-3 rounded border border-red-100 text-sm font-mono text-gray-700 max-h-60 overflow-auto">
          <pre>{JSON.stringify(data, null, 2)}</pre>
        </div>
      )}
    </Card>
  );
};
