import {Composition} from 'remotion';
import {
  FEATURE_TOUR_DURATION,
  FEATURE_TOUR_FPS,
  FEATURE_TOUR_HEIGHT,
  FEATURE_TOUR_WIDTH,
  MCPServerFeatureTour,
} from './Typo3McpServerFeatureTour';

export const RemotionRoot = () => {
  return (
    <Composition
      id="MCPServerFeatureTour"
      component={MCPServerFeatureTour}
      durationInFrames={FEATURE_TOUR_DURATION}
      fps={FEATURE_TOUR_FPS}
      width={FEATURE_TOUR_WIDTH}
      height={FEATURE_TOUR_HEIGHT}
    />
  );
};
