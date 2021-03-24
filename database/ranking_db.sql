-- Adminer 4.7.7 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS `pokerth_ranking`;
CREATE DATABASE `pokerth_ranking` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;
USE `pokerth_ranking`;

DELIMITER ;;

CREATE FUNCTION `boehmipoints`(xplace int) RETURNS int(11)
    DETERMINISTIC
BEGIN
  CASE xplace
    WHEN 1 THEN
      RETURN(15);
    WHEN 2 THEN
      RETURN(9);
    WHEN 3 THEN
      RETURN(6);
    WHEN 4 THEN
      RETURN(4);
    WHEN 5 THEN
      RETURN(3);
    WHEN 6 THEN
      RETURN(2);
    WHEN 7 THEN
      RETURN(1);
    ELSE
      RETURN(0);
  END CASE;
  RETURN(0);
END;;

CREATE DEFINER=`root`@`localhost` FUNCTION `calc_average_score`(points int, games int) RETURNS int(11)
    DETERMINISTIC
BEGIN
  IF games<100 THEN
    RETURN(ROUND((points*(1000*1000.0/6.2))/games)); -- average 6.2 points per game
  END IF;
  RETURN(ROUND(points*10000.0/6.2)); -- assuming 100 games
END;;

CREATE FUNCTION `calc_average_score_b`(`points` int, `games` int) RETURNS int(11)
    DETERMINISTIC
BEGIN
  IF games<1 THEN
    RETURN(0);
  END IF;
  RETURN (ROUND(100 * points / games));
  -- this is only a scaled version of the average,
  -- such that it is displayed correctly without code changes in profile
END;;

CREATE DEFINER=`root`@`localhost` FUNCTION `calc_final_score`(points int, games int, lastweek int) RETURNS int(11)
    DETERMINISTIC
BEGIN
  DECLARE inactivity_malus INT; -- in percent
  DECLARE xscore INT;
  SET inactivity_malus=0;
  SET xscore=calc_average_score(points,games);
  SET xscore=ROUND(xscore*(10000.0+games)/10000.0); -- small game bonus
  IF games<1 THEN
    RETURN(-1);
  END IF;
  IF games<30 THEN
    SET xscore=ROUND(xscore*games/30.0); -- first 30-day malus
  END IF;
  IF lastweek<6 THEN
--     SET inactivity_malus=5*(6-lastweek); -- appareantly this is wrong
    SET inactivity_malus=5*(10-lastweek); --
  END IF;
  RETURN(ROUND(xscore *(100.0-inactivity_malus)/100.0));
END;;

CREATE FUNCTION `calc_final_score_b`(`points` int, `games` int) RETURNS int(11)
    DETERMINISTIC
BEGIN
  IF games<1 THEN
    RETURN (-1);
  END IF;
  RETURN (ROUND(25 * (100 * points/games) * (1 - 10000 / (10000 + POW(games, 3))))); -- resolution is times 10000
END;;

CREATE DEFINER=`root`@`localhost` FUNCTION `pointsforplace`(xplace int) RETURNS int(11)
    DETERMINISTIC
BEGIN
    CASE xplace
      WHEN 1 THEN
        RETURN(24);
      WHEN 2 THEN
        RETURN(16);
      WHEN 3 THEN
        RETURN(10);
      WHEN 4 THEN
        RETURN(6);
      WHEN 5 THEN
        RETURN(3);
      WHEN 6 THEN
         RETURN(2);
      WHEN 7 THEN
        RETURN(1);
      ELSE
        RETURN(0);
    END CASE;
    RETURN(0);
END;;

CREATE DEFINER=`root`@`localhost` FUNCTION `rank`(xfinal int,xgames int,xid int) RETURNS int(11)
    DETERMINISTIC
BEGIN
  DECLARE res int;
  SELECT (
    SELECT COUNT(*) FROM player_ranking 
      WHERE final_score>xfinal ) + (
    SELECT COUNT(*) FROM player_ranking
      WHERE final_score=xfinal AND season_games>xgames )+(
    SELECT COUNT(*) FROM player_ranking
      WHERE final_score=xfinal AND season_games=xgames
      AND player_id<xid)+1 INTO res;
 RETURN(res);
END;;

CREATE DEFINER=`root`@`localhost` FUNCTION `start_of_this_season`(now datetime) RETURNS datetime
    DETERMINISTIC
BEGIN
 DECLARE month varchar(10);
 SET month = DATE_FORMAT(now,"%m");
 IF month<4 THEN
  RETURN( DATE_FORMAT(now,"%Y-01-01 00:00:00"));
 END IF;
 IF month>3 AND month<7 THEN
--    SET season_start = DATE_FORMAT(now,"%Y-04-01");
   RETURN( DATE_FORMAT(now,"%Y-04-01 00:00:00"));
 END IF;
 IF month>6 AND month<10 THEN
--    SET season_start = DATE_FORMAT(now,"%Y-07-01");
   RETURN( DATE_FORMAT(now,"%Y-07-01 00:00:00"));
 END IF;
 IF month>9 THEN
--    SET season_start = DATE_FORMAT(now,"%Y-10-01");
   RETURN( DATE_FORMAT(now,"%Y-10-01 00:00:00"));
 END IF;
 RETURN(now); -- ERROR
END;;

CREATE DEFINER=`root`@`localhost` PROCEDURE `new_season`( )
BEGIN
  UPDATE `player_ranking` SET final_score=-1, points_sum=0,
     season_games=0, average_score=0,games_seven_days=0 -- everywhere
     WHERE 1;
END;;

CREATE PROCEDURE `updatePointsForGame`( IN x_idgame int(11))
BEGIN
  DECLARE b,a,c,d,e INT;
  DECLARE xplayer,xplace INT;
  DECLARE now,this_game_start,prev_game_start datetime;
  DECLARE cur1 CURSOR FOR SELECT player_idplayer,place FROM `game_has_player` WHERE game_idgame=x_idgame;
  DECLARE CONTINUE HANDLER FOR NOT FOUND
    BEGIN
      SELECT 1 INTO b FROM (SELECT 1) as sdfsdfsd;
    END;
  OPEN cur1;
  SELECT `end_time` INTO now FROM `game` WHERE idgame=x_idgame;
  SELECT `start_time` INTO this_game_start FROM `game` WHERE idgame=x_idgame;
  UPDATE game_has_player
    SET start_time=this_game_start, end_time=now
    WHERE game_idgame=x_idgame; -- update start and end_time
-- starting loop over 10 players from the game
  SET b=0;
  mywhile: LOOP
    FETCH cur1 INTO xplayer,xplace;
    IF b=1 THEN
      LEAVE mywhile;
    END IF;
    SELECT COUNT(*),SUM(boehmipoints(place)) INTO c,d
      FROM `game_has_player`
      WHERE player_idplayer=xplayer AND end_time IS NOT NULL
      ORDER BY end_time DESC; -- read points
    UPDATE player_ranking
      SET `points_sum` = d , `season_games`=c,
        `average_score`=calc_average_score_b(d,c),
        `final_score` = calc_final_score_b(d,c)
      -- `final_score` = 5
      WHERE player_id=xplayer;
  END LOOP mywhile;
-- ending loop
  CLOSE cur1;
END;;

CREATE DEFINER=`root`@`localhost` PROCEDURE `update_activity_malus`( IN time_start datetime, IN time_end datetime)
BEGIN
  DECLARE a,b,xplayer INT;
  DECLARE week_ago datetime; -- considering season_start
  DECLARE cur2 CURSOR FOR SELECT DISTINCT player_idplayer FROM `game_has_player` -- select distinct
    WHERE start_time>=DATE_SUB(time_start,INTERVAL 7 DAY) 
      AND start_time<=DATE_SUB(time_end, INTERVAL 7 DAY);
--   DECLARE CONTINUE HANDLER FOR NOT FOUND SET b=1;
  DECLARE CONTINUE HANDLER FOR NOT FOUND
    BEGIN
      SELECT 1 INTO b FROM (SELECT 1) as sdfsd;
    END;
  SET week_ago = GREATEST(start_of_this_season(time_end),DATE_SUB(time_end,INTERVAL 7 DAY));
  OPEN cur2;
  myloop: LOOP
    FETCH cur2 INTO xplayer;
    -- TODO
    IF b=1 THEN
      LEAVE myloop;
    END IF;
    SELECT COUNT(*) INTO a FROM game_has_player
      WHERE player_idplayer=xplayer AND start_time>=week_ago;
    IF a>6 THEN
      UPDATE player_ranking SET `games_seven_days`=a
        WHERE player_id=xplayer;
    END IF;
    IF a<7 THEN -- not bug free
      UPDATE player_ranking 
        SET `games_seven_days`=a, 
        `final_score` = calc_final_score(`points_sum`,`season_games`,a)
        WHERE player_id=xplayer;
    END IF;
  END LOOP myloop;
  CLOSE cur2;
END;;

DELIMITER ;

DROP TABLE IF EXISTS `admin_player`;
CREATE TABLE `admin_player` (
  `admin_idplayer` int(11) NOT NULL,
  PRIMARY KEY (`admin_idplayer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `avatar_blacklist`;
CREATE TABLE `avatar_blacklist` (
  `id` int(11) NOT NULL,
  `avatar_hash` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `game`;
CREATE TABLE `game` (
  `idgame` int(13) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  PRIMARY KEY (`idgame`),
  KEY `start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS `game_has_player`;
CREATE TABLE `game_has_player` (
  `game_idgame` int(13) NOT NULL,
  `player_idplayer` int(11) NOT NULL,
  `place` int(4) NOT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  KEY `start_time` (`start_time`),
  KEY `player_idplayer` (`player_idplayer`,`start_time`,`place`),
  KEY `game_idgame` (`game_idgame`),
  KEY `player_idplayer_2` (`player_idplayer`,`place`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS `player`;
CREATE TABLE `player` (
  `player_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `password` varbinary(64) NOT NULL,
  `email` varchar(64) NOT NULL,
  `created` datetime NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `country_iso` varchar(32) DEFAULT NULL,
  `gender` enum('','m','f') DEFAULT NULL,
  `avatar_hash` varchar(64) DEFAULT NULL,
  `avatar_mime` enum('','png','jpg','gif') DEFAULT NULL,
  `act_key` varchar(64) NOT NULL,
  `fp` varchar(64) DEFAULT NULL,
  `fpnew` varchar(64) DEFAULT NULL,
  `last_games` varchar(128) DEFAULT NULL,
  `last_ip` varchar(64) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `blocked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`),
  UNIQUE KEY `username_email` (`username`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


DROP TABLE IF EXISTS `player_ranking`;
CREATE TABLE `player_ranking` (
  `player_id` int(11) NOT NULL,
  `final_score` int(11) NOT NULL DEFAULT -1,
  `username` varchar(64) NOT NULL,
  `points_sum` int(11) NOT NULL DEFAULT 0,
  `season_games` int(11) NOT NULL DEFAULT 0,
  `average_score` int(13) NOT NULL DEFAULT 0,
  PRIMARY KEY (`player_id`),
  KEY `final_score` (`final_score`),
  KEY `final_score_2` (`final_score`,`season_games`,`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


DROP TABLE IF EXISTS `reported_avatar`;
CREATE TABLE `reported_avatar` (
  `id` int(11) NOT NULL,
  `idplayer` int(11) NOT NULL,
  `avatar_hash` varchar(128) NOT NULL,
  `avatar_type` varchar(8) NOT NULL,
  `by_idplayer` int(11) DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  `state` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `reported_gamename`;
CREATE TABLE `reported_gamename` (
  `id` int(11) unsigned NOT NULL,
  `game_creator_idplayer` int(11) unsigned DEFAULT NULL,
  `game_idgame` int(11) unsigned DEFAULT NULL,
  `game_name` varchar(64) NOT NULL,
  `by_idplayer` int(11) unsigned DEFAULT NULL,
  `timestamp` datetime NOT NULL,
  `state` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=new, 1=ignore, 2=creator_warned, 3=creator_banned, 4=reporter_sure, 5=reporter_stopspam'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- 2021-03-24 16:22:10