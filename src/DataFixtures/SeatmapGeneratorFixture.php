<?php

namespace App\DataFixtures;

use App\Entity\Seat;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class SeatmapGeneratorFixture extends Fixture implements FixtureGroupInterface
{
    // -------- Usage -------- //
    // Generate Rows + Blocks automatically based on settings
    // 
    // php bin/console doctrine:fixtures:load --group=seatmap --append
    // 
    // seats table will be truncated and filled with new data, but you
    // can disable the line `$this->resetSeatTable($manager);` below

    // -------- Settings -------- //
    // 6 Tische 3 Sektoren
    const ALIGNMENT = 'horizontal';
    const SEATS_PER_ROW = 6;
    const NUMBER_OF_SECTORS = 3;
    const ROW_START_TOP = 90;
    const ROW_START_LEFT = 330;
    const SECTOR_DISTANCE = 40;

    // 8 Tische 2 Sektoren
    // const ALIGNMENT = 'horizontal';
    // const SEATS_PER_ROW = 8;
    // const NUMBER_OF_SECTORS = 2;
    // const ROW_START_TOP = 100;
    // const ROW_START_LEFT = 280;
    // const SECTOR_DISTANCE = 60;

    const SEAT_WIDTH_MULTIPLIER = 2; // same as in settings
    const SEAT_WIDTH = 25; // same as in settings
    const TABLE_DISTANCE_RHYTHM = [5, self::SEAT_WIDTH]; // disance between tables next to each other
    const OPPOSITE_TABLE_DISTANCE = 5;
    
    
    // -------- Seatmap Generator -------- //
    const TYPE = 'seat';
    const FIXTURE_GROUPS = ['seatmap'];
    private $currentSector = 1;
    private $currentSeatNumber = 1;
    private $seatAligments = [
        'horizontal' => 'top-bottom',
        'vertical' => 'left-right',
    ];

    public function resetSeatTable($manager)
    {
        $connection = $manager->getConnection();
        $platform = $connection->getDatabasePlatform();

        // This will truncate the 'seat' table, resetting indexes and IDs
        $connection->executeUpdate($platform->getTruncateTableSQL('seat', true));
    }

    public function generateSeatMapHorizontal()
    {
        $seats = [];
        $posY = self::ROW_START_TOP;
        $posX = self::ROW_START_LEFT;

        for ($sector = 1; $sector <= self::NUMBER_OF_SECTORS; $sector++) {
            for ($row = 1; $row <= 2; $row++) {
                $posX = self::ROW_START_LEFT;
                $rythmIndex = 0;

                for ($rowSeat = 1; $rowSeat <= self::SEATS_PER_ROW; $rowSeat++) {
                    $seatNumber = $this->currentSeatNumber++;
                    $chairPositions = explode('-', $this->seatAligments[self::ALIGNMENT]);

                    $seats[] = [
                        'pos_x' => $posX,
                        'pos_y' => $posY,
                        'sector' => $sector,
                        'seat_number' => $seatNumber,
                        'type' => self::TYPE,
                        'chair_position' => $row % 2 === 0 ? $chairPositions[1] : $chairPositions[0],
                    ];

                    $posX += self::SEAT_WIDTH * self::SEAT_WIDTH_MULTIPLIER + self::TABLE_DISTANCE_RHYTHM[$rythmIndex];
                    $rythmIndex = $rythmIndex >= count(self::TABLE_DISTANCE_RHYTHM) - 1 ? 0 : $rythmIndex + 1;
                }

                $this->seatNumberStartOdd += 2;
                $this->seatNumberStartEven += 2;
                $posY += self::SEAT_WIDTH + self::OPPOSITE_TABLE_DISTANCE;
            }

            $posY += self::SECTOR_DISTANCE;
        }

        return $seats;
    }

    public function generateSeatMapVertical() 
    {
        echo "Not implemented yet.";
        return [];
    }
  
    public function storeSeat($manager, $posX, $posY, $sector, $seatNumber, $type, $chairPosition)
    {
        $seat = (new Seat())
            ->setType($type)
            ->setPosX($posX)->setPosY($posY)
            ->setChairPosition($chairPosition)
            ->setSector($sector)->setSeatNumber($seatNumber)
            ->setName("{$sector}-{$seatNumber}");
        $manager->persist($seat);
    }

    public static function getGroups(): array
    {
        return self::FIXTURE_GROUPS;
    }

    public function load(ObjectManager $manager): void
    {
        $this->resetSeatTable($manager);

        $this->currentSector = 1;
        $this->seatNumberStartOdd = 1;
        $this->seatNumberStartEven = 2;

        $seats = self::ALIGNMENT === 'horizontal' ? $this->generateSeatMapHorizontal() : $this->generateSeatMapVertical();

        foreach ($seats as $seat) {
            $this->storeSeat($manager, $seat['pos_x'], $seat['pos_y'], $seat['sector'], $seat['seat_number'], $seat['type'], $seat['chair_position']);
        }

        $manager->flush();
    }
}